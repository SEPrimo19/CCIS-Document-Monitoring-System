#!/usr/bin/env bash
source "$(dirname "$0")/common.sh"
DB() { C:/xampp/mysql/bin/mysql.exe -u root ccis_dms -N -e "$1"; }

echo "=== FR-21..23 Notifications suite (idempotence, unread badge, mark-read) ==="

SEC="$QA/nt_sec.jar"
F1="$QA/nt_fac1.jar"
rm -f "$SEC" "$F1"
login "$SEC" "secretary@nwssu.edu.ph" "Secretary@123" > /dev/null
login "$F1" "faculty1@nwssu.edu.ph" "Faculty@123" > /dev/null

# --- Setup: publish a 3rd requirement whose deadline is a few days in the
# past (inside both the overdue "today" cutoff and the 30-day dedup floor),
# so hammering the faculty dashboard has a REAL reminder to (not) duplicate --
# proves the dedup guard is actually doing something, not just that nothing
# ever fires. Created with a valid near-future deadline (server rejects a
# past one at creation, verified earlier) then backdated via SQL, same
# technique as setup_fixtures.sh. ---
NEARFUTURE=$(date -d '+2 days' +%Y-%m-%d 2>/dev/null || date -v+2d +%Y-%m-%d)
csrf=$(get_csrf "$SEC" "/admin/requirements/new")
curl -s -o /dev/null -c "$SEC" -b "$SEC" \
  --data-urlencode "doc_type_id=3" \
  --data-urlencode "title=TOS Reminder Fixture" \
  --data-urlencode "deadline=$NEARFUTURE" \
  --data-urlencode "csrf_token=$csrf" \
  "$BASE/admin/requirements"
DB "update requirements set deadline=date_sub(curdate(), interval 3 day) where title='TOS Reminder Fixture';" > /dev/null

# The publish above already raised a same-day "New requirement to submit"
# notification (type='pending') for this requirement via
# Notification::createRequirementAssigned(). Delete it before backdating
# takes effect in this test: generateOverdueReminders()'s dedup guard keys
# ONLY on (submission_id, user_id, type='pending', day) -- see
# app/Models/Notification.php's generateOverdueReminders() -- with no
# distinction between "new requirement assigned" and "requirement overdue"
# even though both share type='pending'. Left in place, that same-day
# "New requirement to submit" row would be mistaken for an already-sent
# overdue reminder and suppress the real one -- NOT reachable through the
# real UI (a requirement can never be published already-overdue; validated
# by source read of RequirementController::validate()), so this is a
# fixture-construction artifact, not the thing under test here. Documented
# as a low-severity dedup-granularity observation in RESULTS.md regardless.
DB "delete n from notifications n join submissions s on s.submission_id=n.submission_id join requirements r on r.requirement_id=s.requirement_id where r.title='TOS Reminder Fixture' and n.type='pending';" > /dev/null

BASELINE=$(DB "select count(*) from notifications where user_id=2;")
echo "baseline notification count for faculty1 (user_id=2): $BASELINE"

# First hit should raise exactly one new "Requirement overdue" reminder for
# the new fixture requirement (Notification::generateOverdueReminders).
curl -s -o /dev/null -c "$F1" -b "$F1" "$BASE/faculty/dashboard"
AFTER_FIRST=$(DB "select count(*) from notifications where user_id=2;")
assert_eq "first dashboard hit raises exactly one new reminder" "$((BASELINE + 1))" "$AFTER_FIRST"

# --- FR-21/23 idempotence: hammer both faculty pages 10+ times; count must NOT grow further ---
for i in $(seq 1 12); do
  curl -s -o /dev/null -c "$F1" -b "$F1" "$BASE/faculty/dashboard"
  curl -s -o /dev/null -c "$F1" -b "$F1" "$BASE/faculty/requirements"
done
AFTER_HAMMER=$(DB "select count(*) from notifications where user_id=2;")
assert_eq "hammering dashboard+requirements 12x does not create duplicate reminders (idempotent)" "$AFTER_FIRST" "$AFTER_HAMMER"

# --- FR-23: unread badge / notifications page counts agree with DB ---
db_unread=$(DB "select count(*) from notifications where user_id=2 and is_read=0;")
db_total=$(DB "select count(*) from notifications where user_id=2;")
page=$(curl -s -c "$F1" -b "$F1" "$BASE/notifications")
page_unread=$(echo "$page" | grep -oE '\(Unread \([0-9]+\)|Unread \([0-9]+\)' | grep -oE '[0-9]+' | head -1)
if [ -z "$page_unread" ]; then
  # fall back: look for any "N unread" style text
  page_unread=$(echo "$page" | grep -oiE '[0-9]+[^0-9]{0,10}unread' | grep -oE '^[0-9]+' | head -1)
fi
echo "db_unread=$db_unread db_total=$db_total page_unread_extracted=$page_unread"
if [ -n "$page_unread" ]; then
  assert_eq "notifications page unread count matches DB" "$db_unread" "$page_unread"
else
  fail "could not locate an unread count on the notifications page to verify (see raw grep above -- extraction pattern may need adjusting, not necessarily an app defect)"
fi

unread_filtered=$(curl -s -c "$F1" -b "$F1" "$BASE/notifications?filter=unread")
unread_rows=$(echo "$unread_filtered" | grep -oc 'class="btn-sm[^"]*">Mark read<')
# Fallback pattern if the "mark read" button text/class differs
if [ "$unread_rows" = "0" ]; then unread_rows=$(echo "$unread_filtered" | grep -c 'notification'); fi

# --- IDOR on mark-read, THEN legitimate mark-read on the SAME id (ordered
# this way so the IDOR attempt is verified against a still-unread row no
# matter how many unread notifications faculty1 happens to have): faculty2
# marking faculty1's notification read must not affect it; faculty1 then
# marking that SAME notification read must succeed. ---
target_id=$(DB "select notification_id from notifications where user_id=2 and is_read=0 order by notification_id limit 1;")
if [ -z "$target_id" ]; then
  fail "no unread notification available to test mark-read / IDOR against (unexpected given the overdue reminder just generated)"
else
  F2="$QA/nt_fac2.jar"
  rm -f "$F2"
  login "$F2" "faculty2@nwssu.edu.ph" "Faculty@123" > /dev/null
  csrf2=$(get_csrf "$F2" "/notifications")
  curl -s -o /dev/null -c "$F2" -b "$F2" --data-urlencode "csrf_token=$csrf2" -X POST "$BASE/notifications/$target_id/read"
  after_idor=$(DB "select is_read from notifications where notification_id=$target_id;")
  assert_eq "faculty2 cannot mark faculty1's notification read (IDOR-safe, scoped by user_id in the UPDATE)" "0" "$after_idor"

  csrf=$(get_csrf "$F1" "/notifications")
  curl -s -o /dev/null -c "$F1" -b "$F1" --data-urlencode "csrf_token=$csrf" --data-urlencode "filter=all" -X POST "$BASE/notifications/$target_id/read"
  new_unread=$(DB "select count(*) from notifications where user_id=2 and is_read=0;")
  assert_eq "faculty1 marking their own notification read decrements unread count by 1" "$((db_unread - 1))" "$new_unread"
  new_total=$(DB "select count(*) from notifications where user_id=2;")
  assert_eq "total notification count unchanged after mark-read" "$db_total" "$new_total"
fi

# --- Mark all read ---
csrf=$(get_csrf "$F1" "/notifications")
curl -s -o /dev/null -c "$F1" -b "$F1" --data-urlencode "csrf_token=$csrf" --data-urlencode "filter=all" -X POST "$BASE/notifications/read-all"
remaining_unread=$(DB "select count(*) from notifications where user_id=2 and is_read=0;")
assert_eq "mark-all-read leaves zero unread for faculty1" "0" "$remaining_unread"

result_line
