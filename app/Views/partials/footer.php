        </main>
        <footer class="foot">
            <p>CCIS Document Monitoring System &middot; Northwest Samar State University</p>
        </footer>
    </div><?php // /.app-main ?>
</div><?php // /.app-shell ?>
<?php // FR-41 document modal. A native <dialog> rather than a hand-rolled
     // overlay: it gives focus trapping, Escape to close, inert background and
     // the backdrop for free, and getting those right by hand is where
     // home-made modals become unusable with a keyboard or a screen reader.
     // Empty until a document is opened; app.js fills it. Hidden entirely
     // without script, where the View links stay ordinary navigation. ?>
<dialog id="doc-modal" class="doc-modal" aria-labelledby="doc-modal-title">
    <div class="doc-modal-head">
        <h2 class="doc-modal-title" id="doc-modal-title">Document</h2>
        <div class="doc-modal-actions">
            <?php // The full page still exists and is the better surface for a long
                  // document — the dialog is capped at a share of the viewport. It was
                  // reachable only by ctrl-click once the modal started intercepting
                  // View, which is not a thing anyone discovers. href is filled in by
                  // app.js when a document is opened; deliberately NOT data-doc-view,
                  // so this one link is left to navigate normally. ?>
            <a class="doc-modal-full" data-doc-full href="#" hidden>Open full page</a>
            <button type="button" class="doc-modal-close" data-doc-close aria-label="Close document">&times;</button>
        </div>
    </div>
    <div class="doc-modal-body" data-doc-target>
        <p class="muted-note">Loading&hellip;</p>
    </div>
</dialog>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
