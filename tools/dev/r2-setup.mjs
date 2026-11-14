// Builds the readiness fixture through the real forms: suppliers, customers and items, on a database that
// already has accounting turned on (see r2-setup.sh).
import { admin, sql, one } from './r2-lib.mjs';

const c = await admin();
const created = {};

const supplier = async (company, extra = {}) => {
    const r = await c.submitForm('/suppliers/view', { company_name: company, first_name: 'R2', last_name: company, ...extra });
    if (!r.data?.success) throw new Error(`supplier ${company}: ${r.text.slice(0, 200)}`);
    return Number(one(`SELECT person_id FROM ospos_suppliers WHERE company_name='${company}'`));
};
const customer = async (first, last, extra = {}) => {
    const r = await c.submitForm('/customers/view', { first_name: first, last_name: last, ...extra });
    if (!r.data?.success) throw new Error(`customer ${first}: ${r.text.slice(0, 200)}`);
    return Number(one(`SELECT person_id FROM ospos_people WHERE first_name='${first}' AND last_name='${last}' ORDER BY person_id DESC LIMIT 1`));
};
const noTax = { 'tax_names[]': ['Zero', 'Zero2'], 'tax_percents[]': ['0', '0'] };
const item = async (code, name, cost, price, extra = noTax) => {
    const r = await c.submitForm('/items/view', { item_number: code, name, category: 'R2', cost_price: cost, unit_price: price, ...extra });
    if (!r.data?.success) throw new Error(`item ${code}: ${r.text.slice(0, 300)}`);
    return Number(one(`SELECT item_id FROM ospos_items WHERE item_number='${code}'`));
};

created.supA = await supplier('R2 Supplier A');
created.supB = await supplier('R2 Supplier B');
created.cust1 = await customer('R2Cust', 'One');
created.cust2 = await customer('R2Cust', 'Two');
created.cust3 = await customer('R2Cust', 'Three');
created.itemP = await item('R2-P', 'R2 Plain item', '10.00', '25.00');
created.itemQ = await item('R2-Q', 'R2 Second item', '20.00', '50.00');
created.itemT = await item('R2-T', 'R2 Taxed item 15', '40.00', '100.00', { 'tax_names[]': ['VAT15', 'VAT2'], 'tax_percents[]': ['15', '0'] });
created.itemU = await item('R2-U', 'R2 Taxed item 5', '8.00', '20.00', { 'tax_names[]': ['VAT5', 'VAT2'], 'tax_percents[]': ['5', '0'] });
console.log(JSON.stringify(created));
console.log(sql('SELECT item_id,item_number,cost_price,unit_price FROM ospos_items WHERE item_number LIKE "R2-%"'));
console.log(sql('SELECT item_id,name,percent FROM ospos_items_taxes'));
