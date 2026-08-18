<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerConnexion();

global $pdo;

$stmt = $pdo->prepare(
    'SELECT * FROM notifications WHERE id_utilisateur_destinataire = :id ORDER BY lu ASC, date_creation DESC LIMIT 100'
);
$stmt->execute(['id' => $_SESSION['id_utilisateur']]);
$notifications = $stmt->fetchAll();

$couleursNiveau = ['critique' => 'bg-danger', 'important' => 'bg-warning text-dark', 'info' => 'bg-info text-dark'];
$iconesNiveau = ['critique' => 'bi-exclamation-octagon-fill', 'important' => 'bi-exclamation-triangle-fill', 'info' => 'bi-info-circle-fill'];

$jetonCSRF = genererJetonCSRF();
$titrePage = 'Notifications';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><i class="bi bi-bell me-2 text-navy"></i>Notifications</h1>
    <?php if (!empty($notifications)): ?>
        <form method="post" action="marquer_tout_lu.php">
            <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Tout marquer comme lu</button>
        </form>
    <?php endif; ?>
</div>

<div class="card shadow-sm border-0">
    <div class="list-group list-group-flush">
        <?php if (empty($notifications)): ?>
            <div class="text-center text-muted py-5">Aucune notification.</div>
        <?php endif; ?>
        <?php foreach ($notifications as $notif): ?>
            <div class="list-group-item d-flex align-items-start gap-3 <?= $notif['lu'] ? '' : 'bg-light' ?>">
                <span class="badge <?= $couleursNiveau[$notif['niveau']] ?? 'bg-secondary' ?> rounded-circle p-2">
                    <i class="bi <?= $iconesNiveau[$notif['niveau']] ?? 'bi-bell' ?>"></i>
                </span>
                <div class="flex-grow-1">
                    <div class="fw-semibold"><?= e($notif['titre']) ?> <?= !$notif['lu'] ? '<span class="badge bg-navy ms-1">Nouveau</span>' : '' ?></div>
                    <div class="small text-muted"><?= e($notif['message']) ?></div>
                    <div class="small text-muted"><?= e(date('d/m/Y H:i', strtotime($notif['date_creation']))) ?></div>
                </div>
                <?php if ($notif['lien_cible']): ?>
                    <a href="marquer_lu.php?id=<?= (int) $notif['id_notification'] ?>" class="btn btn-sm btn-outline-secondary text-nowrap">Voir le dossier</a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
