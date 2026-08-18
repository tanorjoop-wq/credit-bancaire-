<?php
/**
 * Copilote IA — drawer latéral consultatif, inclus par les vues Demandes,
 * Analyse financière et Comité. Auto-suffisant : ne dépend d'aucune variable
 * du fichier incluant (sauf $pdo, déjà global partout) — évite toute collision
 * de nom de variable selon la page hôte. Recalcule à partir des données déjà
 * en base (aucune nouvelle règle de scoring) via MoteurIntelligenceCredit,
 * déjà utilisé et testé dans modules/comite/synthese.php.
 */
require_once __DIR__ . '/AnalyseFinanciere.php';
require_once __DIR__ . '/MoteurIntelligenceCredit.php';

global $pdo;

$copiloteIdDemande = isset($idDemande) ? (int) $idDemande : 0;
if ($copiloteIdDemande <= 0 && isset($idClient) && (int) $idClient > 0) {
    $stmtCopiloteDemande = $pdo->prepare(
        "SELECT id_demande FROM demandes_credit WHERE id_client = :id AND statut != 'refuse' ORDER BY id_demande DESC LIMIT 1"
    );
    $stmtCopiloteDemande->execute(['id' => (int) $idClient]);
    $copiloteIdDemande = (int) $stmtCopiloteDemande->fetchColumn();
}

$copiloteAvis = null;
$copiloteDemande = null;
$copiloteScoringAvance = null;
$copiloteBadge = null;

if ($copiloteIdDemande > 0) {
    $stmtCD = $pdo->prepare('SELECT d.*, c.* FROM demandes_credit d JOIN clients c ON c.id_client = d.id_client WHERE d.id_demande = :id');
    $stmtCD->execute(['id' => $copiloteIdDemande]);
    $copiloteDemande = $stmtCD->fetch();

    if ($copiloteDemande) {
        $stmtCSA = $pdo->prepare('SELECT * FROM vue_scoring_avance_actuel WHERE id_demande = :id');
        $stmtCSA->execute(['id' => $copiloteIdDemande]);
        $copiloteScoringAvance = $stmtCSA->fetch() ?: null;

        $stmtCG = $pdo->prepare("SELECT COALESCE(SUM(valeur_estimee),0) FROM garanties WHERE id_demande = :id AND statut != 'rejetee'");
        $stmtCG->execute(['id' => $copiloteIdDemande]);
        $copiloteTotalGaranties = (float) $stmtCG->fetchColumn();

        $stmtCP = $pdo->prepare('SELECT COALESCE(SUM(valeur_estimee),0) FROM patrimoine_client WHERE id_client = :id');
        $stmtCP->execute(['id' => $copiloteDemande['id_client']]);
        $copilotePatrimoineNet = (float) $stmtCP->fetchColumn();

        $stmtCDF = $pdo->prepare('SELECT * FROM donnees_financieres WHERE id_client = :id ORDER BY date_exercice DESC, id_donnee DESC LIMIT 1');
        $stmtCDF->execute(['id' => $copiloteDemande['id_client']]);
        $copiloteDonneesFinancieres = $stmtCDF->fetch();

        $copiloteRatios = null;
        if ($copiloteDonneesFinancieres) {
            $copiloteAnalyseur = new AnalyseFinanciere($pdo);
            $copiloteRatios = $copiloteDemande['type_client'] === 'entreprise'
                ? $copiloteAnalyseur->calculerEntreprise($copiloteDonneesFinancieres, 0)
                : $copiloteAnalyseur->calculerParticulier($copiloteDemande, $copiloteDonneesFinancieres, 0);
        }

        $copiloteMoteur = new MoteurIntelligenceCredit();
        $copiloteAvis = $copiloteMoteur->evaluer(
            $copiloteDemande, $copiloteDemande, $copiloteRatios, $copiloteScoringAvance,
            $copilotePatrimoineNet, $copiloteTotalGaranties
        );

        if ($copiloteScoringAvance) {
            $noteCopilote = $copiloteScoringAvance['note_globale'];
            if (in_array($noteCopilote, ['A+', 'A', 'B+'], true)) {
                $copiloteBadge = ['Favorable', 'success'];
            } elseif (in_array($noteCopilote, ['B', 'C+', 'C'], true)) {
                $copiloteBadge = ['Ajustement recommandé', 'warning'];
            } else {
                $copiloteBadge = ['Risque élevé', 'danger'];
            }
        }
    }
}
?>
<button type="button" class="copilote-fab" onclick="document.getElementById('copiloteDrawer').classList.add('open'); document.getElementById('copiloteBackdrop').classList.add('open');" title="Copilote IA">
    <i class="bi bi-stars"></i>
</button>

<div id="copiloteBackdrop" class="copilote-backdrop" onclick="document.getElementById('copiloteDrawer').classList.remove('open'); this.classList.remove('open');"></div>

<aside id="copiloteDrawer" class="copilote-drawer">
    <div class="copilote-header">
        <span class="fw-semibold"><i class="bi bi-stars me-2"></i>Copilote IA — Analyste</span>
        <button type="button" class="btn-close btn-close-white" onclick="document.getElementById('copiloteDrawer').classList.remove('open'); document.getElementById('copiloteBackdrop').classList.remove('open');"></button>
    </div>
    <div class="copilote-body">
        <?php if (!$copiloteDemande): ?>
            <p class="text-muted small">Aucune demande de crédit associée pour le moment — le Copilote s'active dès qu'un dossier existe.</p>
        <?php else: ?>
            <p class="text-muted small mb-2">Dossier <?= e($copiloteDemande['reference']) ?> — avis consultatif, ne remplace jamais la décision de l'analyste/comité.</p>

            <?php if ($copiloteBadge): ?>
                <div class="text-center mb-3">
                    <span class="badge bg-<?= $copiloteBadge[1] ?> fs-6 px-3 py-2">Pré-décision suggérée : <?= $copiloteBadge[0] ?></span>
                </div>
            <?php else: ?>
                <div class="alert alert-secondary small">Scoring avancé non encore calculé pour ce dossier — <a href="<?= BASE_URL ?>/modules/demandes/voir.php?id=<?= (int) $copiloteIdDemande ?>">le calculer</a> pour activer la pré-décision.</div>
            <?php endif; ?>

            <?php if (!empty($copiloteAvis['incoherences'])): ?>
                <div class="alert alert-danger small">
                    <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Incohérences détectées</div>
                    <ul class="mb-0 ps-3">
                        <?php foreach ($copiloteAvis['incoherences'] as $inc): ?><li><?= e($inc) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="copilote-section-title">Executive summary</div>
            <p class="small"><?= e($copiloteAvis['recommandation']) ?></p>

            <div class="copilote-section-title">Facteurs positifs</div>
            <?php if (empty($copiloteAvis['forces'])): ?>
                <p class="text-muted small">Aucun facteur positif marquant identifié.</p>
            <?php else: ?>
                <?php foreach (array_slice($copiloteAvis['forces'], 0, 3) as $f): ?>
                    <div class="copilote-xai-card copilote-xai-positif"><i class="bi bi-plus-circle-fill me-2"></i><?= e($f) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="copilote-section-title">Facteurs de risque</div>
            <?php if (empty($copiloteAvis['faiblesses'])): ?>
                <p class="text-muted small">Aucun facteur de risque marquant identifié.</p>
            <?php else: ?>
                <?php foreach (array_slice($copiloteAvis['faiblesses'], 0, 3) as $f): ?>
                    <div class="copilote-xai-card copilote-xai-negatif"><i class="bi bi-dash-circle-fill me-2"></i><?= e($f) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>

            <p class="text-muted mt-3 mb-0" style="font-size:0.7rem;">
                Généré par un moteur de règles déterministe et auditable (pas d'IA générative) — chaque facteur provient d'un calcul déjà vérifié ailleurs dans l'application.
            </p>
        <?php endif; ?>
    </div>
</aside>
