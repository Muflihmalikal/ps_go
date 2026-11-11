</main>

<?php
$__dasar = [
    ['https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js', ''],
];

$__tambahan = [
    'jsbarcode'  => [
        ['https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js', ''],
    ],
    'datatables' => [
        ['https://code.jquery.com/jquery-3.7.0.min.js', ''],
        ['https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js', ''],
        ['https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js', ''],
        ['https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js', ''],
        ['https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js', ''],
        ['https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js', ''],
        ['https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js', ''],
        ['https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js', ''],
    ],
];

$__bawaan = [
    'index.php'      => [],
    'form_sewa.php'  => [],
    'profil.php'     => [],
    'pembayaran.php' => ['jsbarcode'],
    'Pesanan.php'    => ['jsbarcode', 'datatables'],
];

$__halaman = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$__butuh   = $footerLibs ?? ($__bawaan[$__halaman] ?? array_keys($__tambahan));

$__skrip = $__dasar;
foreach ($__butuh as $__nama) {
    if (isset($__tambahan[$__nama])) {
        $__skrip = array_merge($__skrip, $__tambahan[$__nama]);
    }
}

foreach ($__skrip as [$__url, $__sri]) {
    echo '<script src="' . e($__url) . '"'
        . ($__sri !== '' ? ' integrity="' . e($__sri) . '" crossorigin="anonymous"' : '')
        . '></script>' . "\n";
}
?>
<script>
    window.APP_BASE = <?= json_encode($base, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="<?= e($base) ?>assets/js/app.js"></script>
</body>

</html>