/**
 * Capture automatisée des 20 écrans du manuel utilisateur (Puppeteer).
 *
 * Contrainte de l'énoncé : une seule connexion, avec charge@creditbanque.sn.
 * Conséquence assumée et documentée dans le rapport final : deux écrans
 * (13_generation_contrat, 15_decaissement) dépendent d'actions réservées à
 * d'autres rôles (comité / administrateur) et peuvent échouer légitimement
 * avec ce compte — le script le signale explicitement plutôt que de
 * fabriquer une capture ou de changer de rôle en douce.
 *
 * Usage : node capture_manuel.js
 */
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');
const XLSX = require('xlsx');

const BASE_URL = 'http://localhost';
const EMAIL = 'charge@creditbanque.sn';
const PASSWORD = 'Charge@2026';
const OUT_DIR = path.join(__dirname, '..', 'captures');
const TMP_DIR = path.join(__dirname, '..', 'captures', '_tmp');

const results = [];

// 1x1 pixel PNG valide (transparent) — fixture pour le test d'upload de document.
const PIXEL_PNG_BASE64 =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

function ensureDirs() {
    if (!fs.existsSync(OUT_DIR)) fs.mkdirSync(OUT_DIR, { recursive: true });
    if (!fs.existsSync(TMP_DIR)) fs.mkdirSync(TMP_DIR, { recursive: true });
}

async function step(name, fn) {
    try {
        await fn();
        results.push({ name, status: 'succès' });
        console.log(`✔ ${name}`);
    } catch (err) {
        results.push({ name, status: 'échec', error: err.message });
        console.log(`✘ ${name} — ${err.message}`);
    }
}

async function shoot(page, filename) {
    await page.screenshot({ path: path.join(OUT_DIR, filename), fullPage: true });
}

async function gotoIdle(page, url) {
    await page.goto(url, { waitUntil: 'networkidle0', timeout: 45000 });
}

async function submitAndWait(page, clickSelector) {
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 45000 }),
        page.click(clickSelector),
    ]);
}

/** Autorise les téléchargements de fichiers sur cet onglet (doit être une session CDP de page, pas de browser). */
async function enableDownloads(page, downloadPath) {
    const client = await page.target().createCDPSession();
    await client.send('Page.setDownloadBehavior', { behavior: 'allow', downloadPath });
}

/** Attend l'apparition d'un nouveau fichier (non présent dans `exclude`) avec l'extension donnée. */
async function waitForNewFile(dir, ext, exclude, timeoutMs) {
    const start = Date.now();
    while (Date.now() - start < timeoutMs) {
        const files = fs.readdirSync(dir).filter((f) => f.endsWith(ext) && !exclude.has(f));
        if (files.length) return path.join(dir, files[0]);
        await new Promise((r) => setTimeout(r, 400));
    }
    return null;
}

/** Cherche dans une page-liste le premier lien "Détails"/"Voir" dont la ligne contient un texte donné. */
async function findRowLinkContaining(page, listUrl, textNeedle, linkTextCandidates) {
    await gotoIdle(page, listUrl);
    return page.evaluate(
        (needle, linkTexts) => {
            const norm = (s) => s.replace(/\s+/g, ' ').trim();
            const rows = Array.from(document.querySelectorAll('table tbody tr'));
            for (const row of rows) {
                if (norm(row.textContent).includes(norm(needle))) {
                    const links = Array.from(row.querySelectorAll('a'));
                    const link = links.find((a) => linkTexts.some((t) => a.textContent.trim().includes(t)));
                    if (link) return link.getAttribute('href');
                }
            }
            return null;
        },
        textNeedle,
        linkTextCandidates
    );
}

(async () => {
    ensureDirs();

    const browser = await puppeteer.launch({
        headless: 'new',
        defaultViewport: { width: 1440, height: 900 },
    });
    const page = await browser.newPage();
    page.setDefaultNavigationTimeout(45000);

    let idClient = null;
    let idDemande = null;

    // --- 01 : page de login (avant connexion) ---
    await step('01_login.png', async () => {
        await gotoIdle(page, `${BASE_URL}/public/login.php`);
        await shoot(page, '01_login.png');
    });

    // --- Connexion (hors capture) ---
    await step('connexion (charge_clientele)', async () => {
        await page.waitForSelector('input[name="email"]');
        await page.type('input[name="email"]', EMAIL);
        await page.type('input[name="mot_de_passe"]', PASSWORD);
        await submitAndWait(page, 'button[type="submit"]');
        if (!page.url().includes('dashboard.php')) {
            throw new Error('Redirection post-login inattendue : ' + page.url());
        }
    });

    // --- 02 : liste des clients ---
    await step('02_liste_clients.png', async () => {
        await gotoIdle(page, `${BASE_URL}/modules/clients/liste.php`);
        await shoot(page, '02_liste_clients.png');
    });

    // --- 03 : formulaire d'ajout client (rempli, avant soumission) ---
    const suffixe = Date.now().toString().slice(-8);
    await step('03_ajouter_client.png', async () => {
        await gotoIdle(page, `${BASE_URL}/modules/clients/ajouter.php`);
        await page.select('select[name="type_client"]', 'particulier');
        await page.type('input[name="nom_raison_sociale"]', 'Sow');
        await page.type('input[name="prenom"]', 'Aliou');
        await page.type('input[name="numero_piece"]', '9' + suffixe + '01');
        await page.type('input[name="telephone"]', '77' + suffixe.slice(0, 7));
        await page.type('input[name="email"]', 'aliou.sow.' + suffixe + '@mail.sn');
        await page.type('input[name="adresse"]', 'Sacré-Cœur 3, Dakar');
        await page.type('input[name="revenu_mensuel"]', '620000');
        await page.type('input[name="anciennete_bancaire_mois"]', '14');
        await shoot(page, '03_ajouter_client.png');
        await submitAndWait(page, 'button[type="submit"]');
    });

    // --- 04 : fiche client (le client qu'on vient de créer, en tête de liste) ---
    await step('04_fiche_client.png', async () => {
        const href = await findRowLinkContaining(page, `${BASE_URL}/modules/clients/liste.php`, 'Sow Aliou', ['Dossier']);
        if (!href) throw new Error('Client nouvellement créé introuvable dans la liste.');
        await gotoIdle(page, new URL(href, `${BASE_URL}/modules/clients/`).toString());
        const match = page.url().match(/[?&]id=(\d+)/);
        if (!match) throw new Error('Impossible d\'extraire id_client depuis ' + page.url());
        idClient = match[1];
        await shoot(page, '04_fiche_client.png');
    });

    // --- 05 : formulaire de nouvelle demande (rempli, avant soumission) ---
    await step('05_nouvelle_demande.png', async () => {
        await gotoIdle(page, `${BASE_URL}/modules/demandes/ajouter.php?id_client=${idClient}`);
        await page.select('select[name="type_credit"]', 'consommation');
        await page.type('input[name="montant_demande"]', '4500000');
        await page.type('input[name="duree_mois"]', '24');
        await page.type('input[name="taux_interet_propose"]', '8.5');
        await page.type('input[name="objet_credit"]', 'Achat de mobilier et équipement');
        await shoot(page, '05_nouvelle_demande.png');
        await submitAndWait(page, 'button[type="submit"]');
    });

    // --- 06 : liste des demandes (badges de statut) ---
    await step('06_liste_demandes.png', async () => {
        await gotoIdle(page, `${BASE_URL}/modules/demandes/liste.php`);
        await shoot(page, '06_liste_demandes.png');
    });

    // --- 07 : fiche détaillée de la nouvelle demande ---
    await step('07_fiche_demande.png', async () => {
        idDemande = await page.evaluate(() => {
            const row = document.querySelector('table tbody tr');
            const link = row ? row.querySelector('a[href*="voir.php?id="]') : null;
            if (!link) return null;
            const m = link.getAttribute('href').match(/id=(\d+)/);
            return m ? m[1] : null;
        });
        if (!idDemande) throw new Error('Nouvelle demande introuvable en tête de liste.');
        await gotoIdle(page, `${BASE_URL}/modules/demandes/voir.php?id=${idDemande}`);
        await shoot(page, '07_fiche_demande.png');
    });

    // --- 08 : résultat du scoring ---
    await step('08_scoring.png', async () => {
        await submitAndWait(page, 'form[action="scoring.php"] button[type="submit"]');
        await shoot(page, '08_scoring.png');
    });

    // --- 09 : formulaire de décision workflow (transmission au comité) ---
    await step('09_decision_workflow.png', async () => {
        await page.waitForSelector('form[action="decision.php"] textarea[name="commentaire"]');
        await page.type('form[action="decision.php"] textarea[name="commentaire"]', 'Dossier complet, revenus vérifiés, transmis pour avis du comité.');
        await shoot(page, '09_decision_workflow.png');
    });

    // --- 10 : historique des décisions ---
    await step('10_historique_workflow.png', async () => {
        await submitAndWait(page, 'form[action="decision.php"] button[type="submit"]');
        await shoot(page, '10_historique_workflow.png');
    });

    // --- 11 : formulaire d'ajout de garantie ---
    await step('11_ajout_garantie.png', async () => {
        await page.waitForSelector('form[action="garantie.php"] select[name="type_garantie"]');
        await page.select('form[action="garantie.php"] select[name="type_garantie"]', 'domiciliation_salaire');
        await page.type('form[action="garantie.php"] input[name="description"]', 'Domiciliation du salaire employeur');
        await page.type('form[action="garantie.php"] input[name="valeur_estimee"]', '620000');
        await shoot(page, '11_ajout_garantie.png');
        await submitAndWait(page, 'form[action="garantie.php"] button[type="submit"]');
    });

    // --- 12 : formulaire d'upload de document ---
    await step('12_upload_document.png', async () => {
        const fixturePath = path.join(TMP_DIR, 'piece_justificative.png');
        fs.writeFileSync(fixturePath, Buffer.from(PIXEL_PNG_BASE64, 'base64'));
        await page.waitForSelector('form[action="document_upload.php"] input[name="type_document"]');
        await page.type('form[action="document_upload.php"] input[name="type_document"]', 'Bulletin de salaire');
        const fileInput = await page.$('form[action="document_upload.php"] input[type="file"]');
        await fileInput.uploadFile(fixturePath);
        await shoot(page, '12_upload_document.png');
        await submitAndWait(page, 'form[action="document_upload.php"] button[type="submit"]');
    });

    // --- 13 : écran de génération de contrat ---
    // Nécessite une demande statut=approuve SANS contrat encore généré — ce statut
    // n'est atteignable que par une décision de comité (rôle comite_direction/admin),
    // impossible avec le seul compte charge_clientele imposé par la consigne.
    await step('13_generation_contrat.png', async () => {
        await gotoIdle(page, `${BASE_URL}/modules/demandes/liste.php?statut=approuve`);
        const hrefs = await page.$$eval('table tbody tr a[href*="voir.php?id="]', (as) =>
            as.map((a) => a.getAttribute('href'))
        );
        let found = false;
        for (const href of hrefs.slice(0, 15)) {
            await gotoIdle(page, new URL(href, `${BASE_URL}/modules/demandes/`).toString());
            const hasButton = await page.evaluate(() => document.body.innerText.includes('Générer le contrat'));
            if (hasButton) {
                await shoot(page, '13_generation_contrat.png');
                found = true;
                break;
            }
        }
        if (!found) {
            throw new Error(
                "Aucune demande approuvée sans contrat trouvée (le contrat est généré automatiquement dès l'approbation par le comité) — écran non atteignable avec le rôle charge_clientele seul."
            );
        }
    });

    // --- 14 : échéancier d'amortissement ---
    let contratExempleHref = null;
    await step('14_echeancier.png', async () => {
        await gotoIdle(page, `${BASE_URL}/modules/contrats/liste.php`);
        contratExempleHref = await page.$eval('table tbody tr a[href*="voir.php?id="]', (a) => a.getAttribute('href'));
        await gotoIdle(page, new URL(contratExempleHref, `${BASE_URL}/modules/contrats/`).toString());
        await shoot(page, '14_echeancier.png');
    });

    // --- 15 : bouton signature/décaissement ---
    // Le bouton "Décaisser le crédit" est réservé au rôle administrateur dans le code
    // (modules/contrats/voir.php) — invisible pour charge_clientele quel que soit le contrat.
    await step('15_decaissement.png', async () => {
        const href = await findRowLinkContaining(page, `${BASE_URL}/modules/contrats/liste.php`, 'Signé', ['Détails']);
        if (!href) throw new Error('Aucun contrat au statut "Signé" trouvé pour illustrer cet écran.');
        await gotoIdle(page, new URL(href, `${BASE_URL}/modules/contrats/`).toString());
        const hasDecaisserButton = await page.evaluate(() => document.body.innerText.includes('Décaisser le crédit'));
        if (!hasDecaisserButton) {
            throw new Error(
                'Bouton "Décaisser le crédit" absent du DOM pour le rôle charge_clientele (réservé à administrateur) — capture non réalisable avec le compte imposé par la consigne.'
            );
        }
        await shoot(page, '15_decaissement.png');
    });

    // --- 16 : formulaire (inline, pas de modal dans cette appli) d'enregistrement d'un remboursement ---
    await step('16_remboursement.png', async () => {
        const href = await findRowLinkContaining(page, `${BASE_URL}/modules/contrats/liste.php`, 'Décaissé', ['Détails']);
        if (!href) throw new Error('Aucun contrat décaissé trouvé.');
        await gotoIdle(page, new URL(href, `${BASE_URL}/modules/contrats/`).toString());
        const hasPayForm = await page.evaluate(() => !!document.querySelector('form[action="payer.php"]'));
        if (!hasPayForm) {
            throw new Error('Aucune échéance impayée disponible sur ce contrat pour afficher le formulaire de remboursement (Nota : l\'application utilise un formulaire inline par échéance, pas une fenêtre modale).');
        }
        await shoot(page, '16_remboursement.png');
    });

    // --- 17 : échéance marquée en retard (si disponible) ---
    await step('17_echeance_retard.png', async () => {
        await gotoIdle(page, `${BASE_URL}/modules/recouvrement/liste.php`);
        const hasImpayes = await page.evaluate(() => document.body.innerText.includes('Tranche'));
        if (!hasImpayes) {
            throw new Error('Aucune échéance en retard/impayée disponible actuellement dans le portefeuille (écran marqué "si disponible" dans la consigne).');
        }
        await shoot(page, '17_echeance_retard.png');
    });

    // --- 18 : tableau de bord complet ---
    await step('18_dashboard.png', async () => {
        await gotoIdle(page, `${BASE_URL}/public/dashboard.php`);
        await new Promise((r) => setTimeout(r, 600)); // laisse Chart.js terminer son animation
        await shoot(page, '18_dashboard.png');
    });

    // --- 19 : PDF exporté ouvert ---
    // demandes_pdf.php force un téléchargement (DOMPDF stream avec Attachment=true),
    // ce n'est donc pas une navigation classique : on active les téléchargements sur
    // l'onglet courant, on déclenche le clic, on attend le fichier sur disque, puis on
    // l'ouvre dans un nouvel onglet (visionneuse PDF intégrée de Chrome) pour la capture.
    await step('19_export_pdf.png', async () => {
        await enableDownloads(page, TMP_DIR);
        await gotoIdle(page, `${BASE_URL}/modules/demandes/liste.php`);
        const avant = new Set(fs.readdirSync(TMP_DIR).filter((f) => f.endsWith('.pdf')));
        await page.click('a[href*="demandes_pdf.php"]');
        const downloaded = await waitForNewFile(TMP_DIR, '.pdf', avant, 15000);
        if (!downloaded) throw new Error("Le fichier PDF n'a pas été téléchargé dans le délai imparti.");

        const pdfPage = await browser.newPage();
        await pdfPage.setViewport({ width: 1440, height: 900 });
        await pdfPage.goto('file://' + downloaded, { waitUntil: 'networkidle0', timeout: 15000 });
        await new Promise((r) => setTimeout(r, 3500)); // laisse le viewer PDF natif de Chrome se peindre
        await pdfPage.screenshot({ path: path.join(OUT_DIR, '19_export_pdf.png') });
        await pdfPage.close();
    });

    // --- 20 : contenu du fichier Excel exporté ---
    await step('20_export_excel.png', async () => {
        await enableDownloads(page, TMP_DIR);
        await gotoIdle(page, `${BASE_URL}/modules/clients/liste.php`);
        const avant = new Set(fs.readdirSync(TMP_DIR).filter((f) => f.endsWith('.xlsx')));
        await page.click('a[href*="clients_excel.php"]');
        const downloaded = await waitForNewFile(TMP_DIR, '.xlsx', avant, 15000);
        if (!downloaded) throw new Error("Le fichier Excel n'a pas été téléchargé dans le délai imparti.");

        const workbook = XLSX.readFile(downloaded);
        const sheet = workbook.Sheets[workbook.SheetNames[0]];
        const html = XLSX.utils.sheet_to_html(sheet);

        const renderPage = await browser.newPage();
        await renderPage.setViewport({ width: 1440, height: 900 });
        await renderPage.setContent(`
            <html><head><meta name="color-scheme" content="light"><style>
                html, body { background: #ffffff; color: #1a1a1a; }
                body { font-family: Arial, sans-serif; padding: 20px; }
                table { border-collapse: collapse; font-size: 12px; background: #ffffff; }
                td, th { border: 1px solid #ccc; padding: 4px 8px; color: #1a1a1a; }
                th { background: #1a2b4c; color: #fff; }
            </style></head><body>
                <h3>Contenu de l'export Excel — liste_clients.xlsx</h3>
                ${html}
            </body></html>
        `);
        await renderPage.screenshot({ path: path.join(OUT_DIR, '20_export_excel.png'), fullPage: true });
        await renderPage.close();
    });

    await browser.close();

    // --- Rapport final ---
    const succes = results.filter((r) => r.status === 'succès');
    const echecs = results.filter((r) => r.status === 'échec');
    console.log('\n========================================');
    console.log(`Résultat : ${succes.length}/${results.length} étapes réussies`);
    if (echecs.length) {
        console.log('\nÉchecs :');
        echecs.forEach((e) => console.log(`  - ${e.name} : ${e.error}`));
    }
    console.log('========================================\n');
})();
