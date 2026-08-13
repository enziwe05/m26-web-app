<?php
/*
 * Reusable photo lightbox.
 *
 * Include this once (before incl/footer.php) on any page that renders photo
 * thumbnails. Any element with class="photo-view" and a data-full attribute
 * becomes clickable: clicking opens a full-screen overlay showing the image at
 * full size, with Download and Open-in-new-tab links.
 *
 *   <a class='photo-view' href='uploads/x.jpg'
 *      data-full='uploads/x.jpg' data-caption='Optional caption'>
 *       <img src='uploads/x.jpg' alt=''>
 *   </a>
 *
 * No external libraries — matches the app's no-build convention.
 */
?>
<div id='lb-overlay' class='lb-overlay' aria-hidden='true'>
    <button type='button' class='lb-close' id='lb-close' aria-label='Close'>&times;</button>
    <figure class='lb-figure'>
        <img id='lb-image' src='' alt=''>
        <figcaption id='lb-caption' class='lb-caption'></figcaption>
        <div class='lb-actions'>
            <a id='lb-download' href='' download class='btn btn-primary'>Download</a>
            <a id='lb-open' href='' target='_blank' rel='noopener' class='btn btn-secondary'>Open in new tab</a>
        </div>
    </figure>
</div>

<script>
(function () {
    var overlay  = document.getElementById('lb-overlay');
    if (!overlay) return;
    var image    = document.getElementById('lb-image');
    var caption  = document.getElementById('lb-caption');
    var download = document.getElementById('lb-download');
    var openTab  = document.getElementById('lb-open');
    var closeBtn = document.getElementById('lb-close');

    function open(src, cap, name) {
        image.src        = src;
        download.href    = src;
        if (name) download.setAttribute('download', name);
        openTab.href     = src;
        caption.textContent = cap || '';
        caption.style.display = cap ? 'block' : 'none';
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
    function close() {
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        image.src = '';
    }

    // Delegate clicks so it works for any number of thumbnails
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.photo-view');
        if (trigger) {
            e.preventDefault();
            var src = trigger.getAttribute('data-full') || trigger.getAttribute('href');
            var cap = trigger.getAttribute('data-caption') || '';
            var name = src.split('/').pop();
            open(src, cap, name);
            return;
        }
        // Click on the backdrop (not the image/actions) closes
        if (e.target === overlay) close();
    });

    closeBtn.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) close();
    });
})();
</script>
