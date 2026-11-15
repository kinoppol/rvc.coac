import { mountSupplierManager } from '../supplier-modal.js';

// ทะเบียนผู้จำหน่ายสินค้า: every supplier the shop has bought from, with its other names
// (aliases), purchases and amounts; admin / manager merge names typed differently on receipts
// into one supplier, with suggestions of names that are probably the same one.
export async function render(container) {
    await mountSupplierManager(container, { asPage: true });
}
