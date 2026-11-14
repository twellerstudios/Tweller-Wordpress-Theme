<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<script>
/* "Copy" buttons: <button class="tf2-copy" data-copy="text"> */
(function () {
    if (window.tf2CopyWired) return; window.tf2CopyWired = true;
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.tf2-copy');
        if (!b) return;
        e.preventDefault();
        var t = b.getAttribute('data-copy') || '';
        var label = b.textContent;
        var done = function () { b.textContent = 'Copied ✓'; setTimeout(function () { b.textContent = label; }, 1500); };
        var fallback = function () {
            var ta = document.createElement('textarea'); ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (err) { window.prompt('Copy this:', t); }
            ta.remove();
        };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t).then(done, fallback); else fallback();
    });
})();
</script>
