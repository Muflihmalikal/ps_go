<?php
if (!isset($base)) {
  $base = '';
}
$currentUser = current_user();
$halaman = strtolower(basename($_SERVER['SCRIPT_NAME']));
echo "<!-- Halaman: $halaman -->";
function menuAktif($file, $halaman)
{
  return strtolower($file) === $halaman ? 'active shadow-sm' : 'opacity-75';
}
?>
<aside id="sidebar" class="sidebar-bg d-flex flex-column p-3 text-white">
  <a href="<?= $base ?>index.php" class="d-flex align-items-center mb-5 mt-2 text-white text-decoration-none">
    <i class="bi bi-controller fs-2 me-2"></i>
    <div class="lh-sm">
      <span class="fs-5 fw-bold d-block">PS Rental</span>
      <small class="opacity-75 fz-70">Bawa pulang, main di rumah!</small>
    </div>
  </a>

  <ul class="nav nav-pills flex-column mb-auto gap-2">
    <li class="nav-item">
      <a href="<?= $base ?>index.php"
        class="nav-link text-white d-flex align-items-center <?= menuAktif('index.php', $halaman) ?>">
        <i class="bi bi-house-door-fill me-3"></i> Beranda
      </a>
    </li>

    <?php if ($currentUser): ?>
      <li class="nav-item">
        <a href="<?= $base ?>pelanggan/Pesanan.php"
          class="nav-link text-white d-flex align-items-center <?= menuAktif('Pesanan.php', $halaman) ?>">
          <i class="bi bi-receipt me-3"></i> Pesanan Saya
        </a>
      </li>
      <li class="nav-item">
        <a href="<?= $base ?>pelanggan/profil.php"
          class="nav-link text-white d-flex align-items-center <?= menuAktif('profil.php', $halaman) ?>">
          <i class="bi bi-person-fill me-3"></i> Profil
        </a>
      </li>
      <li class="nav-item">
        <a href="<?= $base ?>logout.php"
          class="nav-link text-white opacity-75 d-flex align-items-center">
          <i class="bi bi-box-arrow-right me-3"></i> Logout
        </a>
      </li>
    <?php endif; ?>
  </ul>
</aside>