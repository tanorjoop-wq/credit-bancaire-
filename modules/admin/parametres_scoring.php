<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur']);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierJetonCSRF();

    $parametres = $pdo->query('SELECT cle FROM parametres_scoring')->fetchAll(PDO::FETCH_COLUMN);
    $stmtMaj = $pdo->prepare('UPDATE parametres_scoring SET valeur = :valeur WHERE cle = :cle');

    foreach ($parametres as $cle) {
        if (isset($_POST[$cle]) && is_numeric($_POST[$cle])) {
            $ancienne = $pdo->prepare('SELECT valeur FROM parametres_scoring WHERE cle = :cle');
            $ancienne->execute(['cle' => $cle]);
            $ancienneValeur = $ancienne->fetchColumn();

            $stmtMaj->execute(['valeur' => $_POST[$cle], 'cle' => $cle]);

            if ((float) $ancienneValeur !== (float) $_POST[$cle]) {
                audit_avant_apres($pdo, 'MODIFICATION_PARAMETRE_SCORING', 'parametres_scoring', null, "$cle = $ancienneValeur", "$cle = " . $_POST[$cle]);
            }
        }
    }

    rediriger('parametres_scoring.php?succes=' . urlencode('Paramètres mis à jour.'));
}

$parametres = $pdo->query('SELECT * FROM parametres_scoring ORDER BY cle')->fetchAll();
$jetonCSRF = genererJetonCSRF();
$titrePage = 'Paramètres de scoring';
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-1"><i class="bi bi-sliders me-2 text-navy"></i>Paramètres de scoring</h1>
<p class="text-muted small mb-4">Pondérations et seuils utilisés par le moteur de scoring avancé et l'analyse financière — modifiables sans toucher au code.</p>

<?php if (isset($_GET['succes'])): ?><div class="alert alert-success small"><?= e($_GET['succes']) ?></div><?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <form method="post" action="parametres_scoring.php">
            <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
            <div class="row g-3">
                <?php foreach ($parametres as $p): ?>
                    <div class="col-md-6">
                        <label class="form-label"><?= e($p['description']) ?></label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><?= e($p['cle']) ?></span>
                            <input type="number" step="0.0001" name="<?= e($p['cle']) ?>" class="form-control" value="<?= e($p['valeur']) ?>">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-navy">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<div class="alert alert-info small mt-3">
    <i class="bi bi-info-circle me-1"></i>
    Ces valeurs sont lues au moment du calcul (scoring avancé, ratios financiers) — un changement s'applique
    immédiatement aux <strong>prochains</strong> calculs, sans jamais modifier rétroactivement les évaluations déjà enregistrées.
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
