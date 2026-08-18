<?php
// Ce fichier suppose que includes/auth.php a déjà été inclus
// et que exigerConnexion() ou exigerRole() a déjà été appelé.
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($titrePage) ? e($titrePage) . ' — ' : '' ?>Crédit Bancaire</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/bootstrap-icons.css">
    <?php $versionStyle = @filemtime(__DIR__ . '/../public/assets/css/style.css') ?: time(); ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/style.css?v=<?= $versionStyle ?>">
</head>
<body>
<?php
    global $pdo;
    $cheminActuel = $_SERVER['SCRIPT_NAME'] ?? '';
    $estActif = static fn(string $motif): string => str_contains($cheminActuel, $motif) ? 'active' : '';
    $initiales = static function (string $nomComplet): string {
        $parties = array_filter(explode(' ', $nomComplet));
        $lettres = array_map(static fn($p) => mb_strtoupper(mb_substr($p, 0, 1)), $parties);
        return implode('', array_slice($lettres, 0, 2)) ?: '?';
    };
    $role = $_SESSION['role'] ?? '';
    $notificationsRecentes = [];
    $nbNotificationsNonLues = 0;
    if (!empty($_SESSION['id_utilisateur'])) {
        $stmtNotifHeader = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE id_utilisateur_destinataire = :id AND lu = 0');
        $stmtNotifHeader->execute(['id' => $_SESSION['id_utilisateur']]);
        $nbNotificationsNonLues = (int) $stmtNotifHeader->fetchColumn();

        $stmtNotifRecentes = $pdo->prepare('SELECT * FROM notifications WHERE id_utilisateur_destinataire = :id ORDER BY date_creation DESC LIMIT 5');
        $stmtNotifRecentes->execute(['id' => $_SESSION['id_utilisateur']]);
        $notificationsRecentes = $stmtNotifRecentes->fetchAll();
    }

    // Structure de la sidebar — 4 pôles métier (cf. charte UX)
    $groupesSidebar = [
        'Opérations' => [
            ['Tableau de bord', 'bi-speedometer2', '/public/dashboard.php', '/dashboard.php'],
            ['Clients', 'bi-people', '/modules/clients/liste.php', '/clients/'],
            ['Demandes', 'bi-file-earmark-text', '/modules/demandes/liste.php', '/demandes/'],
            ['Contrats', 'bi-file-earmark-check', '/modules/contrats/liste.php', '/contrats/'],
            ['Recouvrement', 'bi-telephone-outbound', '/modules/recouvrement/liste.php', '/recouvrement/'],
        ],
        'Analyse & Risque' => [
            ['Analyse financière', 'bi-graph-up', '/modules/analyse/liste.php', '/analyse/'],
            ['Comité', 'bi-people-fill', '/modules/comite/file_attente.php', '/comite/', ['administrateur', 'comite_direction']],
            ['Risque', 'bi-shield-exclamation', '/modules/risque/tableau.php', '/risque/', ['administrateur', 'comite_direction']],
            ['Rentabilité', 'bi-cash-coin', '/modules/rentabilite/liste.php', '/rentabilite/', ['administrateur', 'comite_direction']],
            ['Simulateur', 'bi-calculator', '/modules/outils/simulateur.php', '/outils/'],
        ],
        'Conformité & GED' => [
            ['Documents', 'bi-folder2-open', '/modules/documents/liste.php', '/documents/'],
            ['Journal d\'audit', 'bi-journal-text', '/modules/audit/liste.php', '/audit/', ['administrateur']],
            ['Centre d\'exports', 'bi-download', '/modules/exports/centre.php', '/exports/', ['administrateur']],
        ],
        'Administration' => [
            ['Utilisateurs', 'bi-person-gear', '/modules/utilisateurs/liste.php', '/utilisateurs/', ['administrateur']],
            ['Agences', 'bi-building', '/modules/admin/agences.php', '/admin/agences.php', ['administrateur']],
            ['Produits de crédit', 'bi-boxes', '/modules/admin/produits.php', '/admin/produits.php', ['administrateur']],
            ['Paramètres de scoring', 'bi-sliders', '/modules/admin/parametres_scoring.php', '/admin/parametres_scoring.php', ['administrateur']],
        ],
    ];
?>
<div class="app-shell" id="appShell">
    <aside class="sidebar" id="sidebar">
        <a class="sidebar-brand" href="<?= BASE_URL ?>/public/dashboard.php">
            <i class="bi bi-bank2"></i> <span>Crédit Bancaire</span>
        </a>

        <?php foreach ($groupesSidebar as $nomGroupe => $liens): ?>
            <?php
                $liensVisibles = array_filter($liens, static fn($l) => empty($l[4]) || in_array($role, $l[4], true));
                if (empty($liensVisibles)) continue;
            ?>
            <div class="sidebar-group">
                <div class="sidebar-group-title">
                    <span><?= e($nomGroupe) ?></span>
                    <i class="bi bi-chevron-down"></i>
                </div>
                <div class="sidebar-group-links">
                    <?php foreach ($liensVisibles as [$libelle, $icone, $href, $motif]): ?>
                        <a class="sidebar-link <?= $estActif($motif) ?>" href="<?= BASE_URL . $href ?>">
                            <i class="bi <?= $icone ?>"></i><span><?= e($libelle) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <button type="button" class="sidebar-toggle-btn" onclick="toggleSidebarCollapse()">
            <i class="bi bi-layout-sidebar-inset"></i> <span>Réduire le menu</span>
        </button>
    </aside>

    <div class="app-main">
        <header class="topbar">
            <button type="button" class="btn btn-sm btn-outline-secondary d-lg-none" onclick="toggleSidebarMobile()">
                <i class="bi bi-list"></i>
            </button>
            <span class="fw-semibold text-navy d-none d-lg-inline"><?= isset($titrePage) ? e($titrePage) : 'Crédit Bancaire' ?></span>

            <div class="d-flex align-items-center gap-3 ms-auto">
                <div class="dropdown">
                    <a href="#" class="text-secondary position-relative d-inline-block" role="button" data-bs-toggle="dropdown" style="font-size:1.2rem;">
                        <i class="bi bi-bell"></i>
                        <?php if ($nbNotificationsNonLues > 0): ?>
                            <span class="position-absolute badge rounded-pill bg-danger" style="top:-6px;right:-10px;font-size:0.6rem;"><?= $nbNotificationsNonLues > 9 ? '9+' : $nbNotificationsNonLues ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end p-0" style="width: 320px;">
                        <div class="px-3 py-2 fw-semibold border-bottom">Notifications</div>
                        <?php if (empty($notificationsRecentes)): ?>
                            <div class="px-3 py-3 text-muted small">Aucune notification.</div>
                        <?php else: ?>
                            <?php foreach ($notificationsRecentes as $notif): ?>
                                <a href="<?= BASE_URL ?>/modules/notifications/marquer_lu.php?id=<?= (int) $notif['id_notification'] ?>" class="dropdown-item small py-2 <?= $notif['lu'] ? '' : 'bg-light' ?>">
                                    <div class="fw-semibold"><?= e($notif['titre']) ?></div>
                                    <div class="text-muted"><?= e(mb_strimwidth($notif['message'], 0, 60, '…')) ?></div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <a href="<?= BASE_URL ?>/modules/notifications/liste.php" class="dropdown-item text-center small py-2 border-top text-navy fw-semibold">Voir toutes les notifications</a>
                    </div>
                </div>

                <span class="d-flex align-items-center gap-2 small">
                    <span class="badge rounded-circle bg-navy text-white d-inline-flex align-items-center justify-content-center" style="width:28px;height:28px;">
                        <?= e($initiales($_SESSION['nom_complet'] ?? '?')) ?>
                    </span>
                    <span class="d-none d-md-inline">
                        <?= e($_SESSION['nom_complet'] ?? '') ?>
                        <span class="badge bg-light text-navy border ms-1"><?= e($_SESSION['role'] ?? '') ?></span>
                    </span>
                </span>

                <a href="<?= BASE_URL ?>/public/logout.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-arrow-right"></i></a>
            </div>
        </header>

        <main class="content">
