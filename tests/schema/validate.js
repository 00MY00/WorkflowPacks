// Checks the JSON schema itself and validates the shipped packs against it (Ajv, draft 2020-12).
//   npm install ajv@8 && node tests/schema/validate.js
const fs = require('fs');
const path = require('path');
const Ajv2020 = require('ajv/dist/2020');

const root = path.join(__dirname, '..', '..');
const schema = JSON.parse(fs.readFileSync(path.join(root, 'schema', 'freescout-workflow-pack.schema.json'), 'utf8'));
const ajv = new Ajv2020({ allErrors: true, strict: false });
const validate = ajv.compile(schema);

let failed = 0;
const check = (name, data, expected) => {
    const ok = validate(data);
    if (ok !== expected) {
        failed++;
        console.log(`FAIL ${name}: attendu ${expected ? 'valide' : 'invalide'}`, JSON.stringify(validate.errors, null, 1));
    } else {
        console.log(`PASS ${name}`);
    }
};

const packsDir = path.join(root, 'WorkflowPacks', 'Resources', 'packs');
for (const file of fs.readdirSync(packsDir).filter((f) => f.endsWith('.json'))) {
    check(file, JSON.parse(fs.readFileSync(path.join(packsDir, file), 'utf8')), true);
}

const base = JSON.parse(fs.readFileSync(path.join(packsDir, 'tri-emails-multilingue.json'), 'utf8'));
const variant = (fn) => { const copy = JSON.parse(JSON.stringify(base)); fn(copy); return copy; };
check('ET dans un OU refusé', variant((p) => { p.workflows[0].conditions = { any: [{ all: [{ type: 'subject', operator: 'contains', value: 'x' }] }] }; }), false);
check('clé inconnue refusée', variant((p) => { p.workflows[0].enabled = true; }), false);
check('version 2 refusée', variant((p) => { p.version = 2; }), false);
check('règle automatique sans conditions refusée', variant((p) => { delete p.workflows[0].conditions; }), false);
check('règle manuelle sans conditions acceptée', variant((p) => { p.workflows[0].type = 'manual'; delete p.workflows[0].conditions; }), true);

console.log(failed ? `${failed} échec(s)` : 'Schéma OK');
process.exit(failed ? 1 : 0);
