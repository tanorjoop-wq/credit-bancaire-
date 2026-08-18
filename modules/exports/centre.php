<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur']);

global $pdo;

$demandes = $pdo->query(
    "SELECT d.id_demande, d.reference, cl.nom_raison_sociale, cl.prenom
     FROM demandes_credit d JOIN clients cl ON cl.id_client = d.id_client
     ORDER BY d.id_demande DESC"
)->fetchAll();

$contrats = $pdo->query(
    "SELECT c.id_contrat, c.numero_contrat, cl.nom_raison_sociale, cl.prenom
     FROM contrats c
     JOIN demandes_credit d ON d.id_demande = c.id_demande
     JOIN clients cl ON cl.id_client = d.id_client
     ORDER BY c.id_contrat DESC"
)->fetchAll();

$titrePage = "Centre d'exports";
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-4"><i class="bi bi-download me-2 text-navy"></i>Centre d'exports</h1>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-pdf me-1"></i>État des demandes de crédit (PDF)</div>
            <div class="card-body">
                <p class="text-muted small">Liste complète des demandes avec statut, montant et score.</p>
                <a href="demandes_pdf.php" class="btn btn-navy btn-sm" target="_blank">Générer le PDF</a>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-excel me-1"></i>Liste des clients (Excel)</div>
            <div class="card-body">
                <p class="text-muted small">Export complet de la base clients au format .xlsx.</p>
                <a href="clients_excel.php" class="btn btn-navy btn-sm">Générer l'Excel</a>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-pdf me-1"></i>Fiche de synthèse comité (PDF)</div>
            <div class="card-body">
                <p class="text-muted small">Dossier complet d'une demande : scoring, ratios, patrimoine, rentabilité, workflow.</p>
                <form action="fiche_synthese_pdf.php" method="get" target="_blank" class="d-flex gap-2">
                    <select name="id" class="form-select form-select-sm" required>
                        <option value="">-- Choisir une demande --</option>
                        <?php foreach ($demandes as $d): ?>
                            <option value="<?= (int) $d['id_demande'] ?>"><?= e($d['reference']) ?> — <?= e($d['nom_raison_sociale']) ?> <?= e($d['prenom'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-navy btn-sm text-nowrap">Générer</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-excel me-1"></i>Échéancier d'un contrat (Excel)</div>
            <div class="card-body">
                <p class="text-muted small">Tableau d'amortissement complet d'un contrat.</p>
                <form action="echeancier_excel.php" method="get" class="d-flex gap-2">
                    <select name="id" class="form-select form-select-sm" required>
                        <option value="">-- Choisir un contrat --</option>
                        <?php foreach ($contrats as $c): ?>
                            <option value="<?= (int) $c['id_contrat'] ?>"><?= e($c['numero_contrat']) ?> — <?= e($c['nom_raison_sociale']) ?> <?= e($c['prenom'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-navy btn-sm text-nowrap">Générer</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-journal-text me-1"></i>Journal d'audit (Excel)</div>
            <div class="card-body">
                <p class="text-muted small">Export complet de la traçabilité des actions sensibles.</p>
                <a href="audit_excel.php" class="btn btn-navy btn-sm">Générer l'Excel</a>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-bar-graph me-1"></i>Rapport de portefeuille (PDF)</div>
            <div class="card-body">
                <p class="text-muted small">Production, portefeuille, risque et rentabilité consolidés en un document.</p>
                <a href="rapport_portefeuille_pdf.php" class="btn btn-navy btn-sm" target="_blank">Générer le PDF</a>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-lines-fill me-1"></i>Performance des analystes (Excel)</div>
            <div class="card-body">
                <p class="text-muted small">Volume traité, taux d'approbation et délai moyen par chargé de clientèle.</p>
                <a href="performance_analystes_excel.php" class="btn btn-navy btn-sm">Générer l'Excel</a>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
