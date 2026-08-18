<?php
/**
 * Rapport de portefeuille consolidé (Module 12 — Reporting/BI) :
 * Production, Portefeuille, Risque, Rentabilité — mêmes requêtes que le
 * Dashboard (Module 1) et le Risk Management Center (Module 10), pas de
 * duplication de logique de calcul, juste une mise en forme imprimable.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';
exigerRole(['administrateur', 'comite_direction']);

use Dompdf\Dompdf;
use Dompdf\Options;

global $pdo;

$nbClients = (int) $pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
$nbDemandes = (int) $pdo->query('SELECT COUNT(*) FROM demandes_credit')->fetchColumn();
$nbApprouvees = (int) $pdo->query("SELECT COUNT(*) FROM demandes_credit WHERE statut IN ('approuve','decaisse','solde')")->fetchColumn();
$tauxConversion = $nbDemandes > 0 ? round($nbApprouvees / $nbDemandes * 100, 1) : 0;

$encoursTotal = (float) $pdo->query(
    "SELECT COALESCE(SUM(c.montant_accorde - (SELECT COALESCE(SUM(e.capital),0) FROM echeancier e WHERE e.id_contrat = c.id_contrat AND e.statut = 'payee')), 0)
     FROM contrats c WHERE c.statut IN ('decaisse', 'en_defaut')"
)->fetchColumn();

$nbContratsActifs = (int) $pdo->query("SELECT COUNT(*) FROM contrats WHERE statut IN ('decaisse','en_defaut')")->fetchColumn();
$nbContratsImpayes = (int) $pdo->query("SELECT COUNT(DISTINCT id_contrat) FROM echeancier WHERE statut = 'impayee'")->fetchColumn();
$npl = $nbContratsActifs > 0 ? round($nbContratsImpayes / $nbContratsActifs * 100, 2) : 0;

function encoursParTrancheRapport(PDO $pdo, int $seuil): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(c.montant_accorde - (SELECT COALESCE(SUM(e2.capital),0) FROM echeancier e2 WHERE e2.id_contrat = c.id_contrat AND e2.statut = 'payee')), 0)
         FROM contrats c WHERE c.statut IN ('decaisse', 'en_defaut')
           AND EXISTS (SELECT 1 FROM echeancier e WHERE e.id_contrat = c.id_contrat AND e.statut IN ('en_retard','impayee') AND DATEDIFF(CURDATE(), e.date_echeance) >= :s)"
    );
    $stmt->execute(['s' => $seuil]);
    return (float) $stmt->fetchColumn();
}
$par30 = $encoursTotal > 0 ? round(encoursParTrancheRapport($pdo, 30) / $encoursTotal * 100, 1) : 0;

$rentabilite = $pdo->query(
    "SELECT COUNT(*) AS nb, COALESCE(SUM(marge_nette_interet),0) AS mni, COALESCE(SUM(cout_du_risque),0) AS cout_risque, COALESCE(AVG(raroc),0) AS raroc_moyen
     FROM rentabilite_demande"
)->fetch();

$parProduit = $pdo->query(
    "SELECT type_credit, COUNT(*) AS nb, COALESCE(SUM(montant_demande),0) AS montant
     FROM demandes_credit WHERE statut IN ('approuve','decaisse','solde') GROUP BY type_credit ORDER BY montant DESC"
)->fetchAll();

ob_start();
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; }
    h1 { font-size: 16px; color: #1a2b4c; margin-bottom: 2px; }
    .sous-titre { font-size: 9px; color: #666; margin-bottom: 14px; }
    .encadre { border: 1px solid #ccc; border-radius: 3px; padding: 8px 10px; margin-bottom: 10px; }
    .encadre h2 { font-size: 10px; color: #fff; background: #1a2b4c; margin: -8px -10px 6px -10px; padding: 4px 10px; }
    table.kpi { width: 100%; } table.kpi td { padding: 4px; text-align: center; border: 1px solid #eee; }
    table.kpi .val { font-size: 14px; font-weight: bold; color: #1a2b4c; }
    table.produits { width: 100%; border-collapse: collapse; } table.produits th { background: #eef1f6; padding: 4px; text-align: left; }
    table.produits td { padding: 4px; border-bottom: 1px solid #eee; }
</style></head><body>
    <h1>Rapport de portefeuille — Plateforme Crédit Bancaire</h1>
    <div class="sous-titre">Généré le <?= date('d/m/Y à H:i') ?> par <?= e($_SESSION['nom_complet']) ?></div>

    <div class="encadre">
        <h2>Production & Portefeuille</h2>
        <table class="kpi"><tr>
            <td>Clients<br><span class="val"><?= $nbClients ?></span></td>
            <td>Demandes<br><span class="val"><?= $nbDemandes ?></span></td>
            <td>Taux conversion<br><span class="val"><?= $tauxConversion ?>%</span></td>
            <td>Encours actif<br><span class="val"><?= number_format($encoursTotal, 0, ',', ' ') ?> FCFA</span></td>
        </tr></table>
    </div>

    <div class="encadre">
        <h2>Risque</h2>
        <table class="kpi"><tr>
            <td>PAR 30<br><span class="val"><?= $par30 ?>%</span></td>
            <td>NPL<br><span class="val"><?= $npl ?>%</span></td>
            <td>Contrats actifs<br><span class="val"><?= $nbContratsActifs ?></span></td>
            <td>Contrats en défaut<br><span class="val"><?= $nbContratsImpayes ?></span></td>
        </tr></table>
    </div>

    <div class="encadre">
        <h2>Rentabilité</h2>
        <table class="kpi"><tr>
            <td>MNI cumulée<br><span class="val"><?= number_format((float) $rentabilite['mni'], 0, ',', ' ') ?> FCFA</span></td>
            <td>Coût du risque<br><span class="val"><?= number_format((float) $rentabilite['cout_risque'], 0, ',', ' ') ?> FCFA</span></td>
            <td>RAROC moyen<br><span class="val"><?= number_format((float) $rentabilite['raroc_moyen'], 1) ?>%</span></td>
        </tr></table>
    </div>

    <div class="encadre">
        <h2>Répartition par produit</h2>
        <table class="produits">
            <thead><tr><th>Produit</th><th>Nb</th><th>Montant</th></tr></thead>
            <tbody>
            <?php foreach ($parProduit as $p): ?>
                <tr><td><?= e(ucfirst($p['type_credit'])) ?></td><td><?= (int) $p['nb'] ?></td><td><?= number_format((float) $p['montant'], 0, ',', ' ') ?> FCFA</td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body></html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Helvetica');
$options->set('tempDir', sys_get_temp_dir());

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

enregistrerAudit('EXPORT_RAPPORT_PORTEFEUILLE', 'demandes_credit', null, 'Export du rapport de portefeuille consolidé');

$dompdf->stream('rapport_portefeuille_' . date('Y-m-d') . '.pdf', ['Attachment' => false]);
