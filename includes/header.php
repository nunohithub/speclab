<?php
/**
 * SpecLab - Cadernos de Encargos
 * Header partilhado com branding dinâmico por organização
 *
 * Variáveis esperadas antes do include:
 *   $user     - array do getCurrentUser()
 *   $pageTitle - título da página (opcional)
 *   $pageSubtitle - subtítulo (opcional)
 *   $showNav  - mostrar barra de navegação (default: false)
 *   $activeNav - nav item ativo (opcional)
 */

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$branding = getOrgBranding();
$orgNome = $branding['nome'] ?: 'SpecLab';
$orgLogo = $branding['logo'];
$orgCor = $branding['cor'];
$orgCorDark = $branding['cor_dark'];
$orgCorLight = $branding['cor_light'];

// Logo path
$logoSrc = BASE_PATH . '/assets/img/exi_logo.png';
if ($orgLogo) {
    $logoSrc = BASE_PATH . '/uploads/logos/' . $orgLogo;
}

$pageTitle = $pageTitle ?? 'Cadernos de Encargos';
$pageSubtitle = $pageSubtitle ?? 'Sistema de Especificações Técnicas';
$showNav = $showNav ?? false;
$activeNav = $activeNav ?? '';
?>
<!-- CSS Override: cores da organização -->
<style>
    :root {
        --color-primary: <?= sanitize($orgCor) ?>;
        --color-primary-dark: <?= sanitize($orgCorDark) ?>;
        --color-primary-lighter: <?= sanitize($orgCorLight) ?>;
    }
</style>

<!-- HEADER -->
<div class="app-header">
    <div class="logo">
        <img src="<?= $logoSrc ?>" alt="<?= sanitize($orgNome) ?>" onerror="this.style.display='none'">
        <div>
            <h1><?= sanitize($pageTitle) ?></h1>
            <span><?= sanitize($pageSubtitle) ?></span>
        </div>
    </div>
    <div class="header-actions">
        <?php
        $roleLabels = ['super_admin' => 'Super Admin', 'org_admin' => 'Administrador', 'user' => 'Utilizador'];
        $roleLabel = $roleLabels[$user['role']] ?? $user['role'];
        ?>
        <span class="user-info"><?= sanitize($user['nome']) ?> (<?= $roleLabel ?>)</span>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-ghost btn-sm">Sair</a>
    </div>
</div>

<?php if ($showNav): ?>
<!-- NAVIGATION -->
<div class="nav-bar no-print">
    <a href="<?= BASE_PATH ?>/dashboard.php" class="nav-item <?= $activeNav === 'especificacoes' ? 'active' : '' ?>">Dashboard</a>
    <?php if (in_array($user['role'], ['super_admin', 'org_admin'])): ?>
        <a href="<?= BASE_PATH ?>/admin.php?tab=produtos" class="nav-item <?= $activeNav === 'produtos' ? 'active' : '' ?>">Produtos</a>
        <?php if ($user['role'] === 'super_admin' || !empty($_SESSION['org_tem_clientes'])): ?>
            <a href="<?= BASE_PATH ?>/admin.php?tab=clientes" class="nav-item <?= $activeNav === 'clientes' ? 'active' : '' ?>">Clientes</a>
        <?php endif; ?>
        <?php if ($user['role'] === 'super_admin' || !empty($_SESSION['org_tem_fornecedores'])): ?>
            <a href="<?= BASE_PATH ?>/admin.php?tab=fornecedores" class="nav-item <?= $activeNav === 'fornecedores' ? 'active' : '' ?>">Fornecedores</a>
        <?php endif; ?>
        <a href="<?= BASE_PATH ?>/admin.php?tab=utilizadores" class="nav-item <?= $activeNav === 'utilizadores' ? 'active' : '' ?>">Utilizadores</a>
    <?php endif; ?>
    <?php if ($user['role'] === 'super_admin'): ?>
        <a href="<?= BASE_PATH ?>/admin.php?tab=organizacoes" class="nav-item <?= $activeNav === 'organizacoes' ? 'active' : '' ?>">Organizações</a>
        <a href="<?= BASE_PATH ?>/admin.php?tab=legislacao" class="nav-item <?= $activeNav === 'legislacao' ? 'active' : '' ?>">Legislação</a>
        <a href="<?= BASE_PATH ?>/admin.php?tab=ensaios" class="nav-item <?= $activeNav === 'ensaios' ? 'active' : '' ?>">Ensaios</a>
        <a href="<?= BASE_PATH ?>/admin.php?tab=configuracoes" class="nav-item <?= $activeNav === 'configuracoes' ? 'active' : '' ?>">Configurações</a>
        <a href="<?= BASE_PATH ?>/admin.php?tab=planos" class="nav-item <?= $activeNav === 'planos' ? 'active' : '' ?>">Planos</a>
    <?php elseif (in_array($user['role'], ['org_admin', 'user'])): ?>
        <a href="<?= BASE_PATH ?>/admin.php?tab=legislacao" class="nav-item <?= $activeNav === 'legislacao' ? 'active' : '' ?>">Legislação</a>
        <a href="<?= BASE_PATH ?>/admin.php?tab=ensaios" class="nav-item <?= $activeNav === 'ensaios' ? 'active' : '' ?>">Ensaios</a>
        <?php if ($user['role'] === 'org_admin'): ?>
            <a href="<?= BASE_PATH ?>/admin.php?tab=configuracoes" class="nav-item <?= $activeNav === 'configuracoes' ? 'active' : '' ?>">Configurações</a>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>
