/**
 * KASIR IBTIDAIYAH - Web Bluetooth Printer for 58mm Thermal Printers
 * ESC/POS Encoder & Connection Handler
 */

class BluetoothPrinter {
    constructor() {
        this.device = null;
        this.server = null;
        this.characteristic = null;
        // Standard ESC/POS BLE UUIDs used by many generic 58mm thermal printers
        // Try these generic UUIDs if specific one is unknown
        this.primaryServiceUuid = '000018f0-0000-1000-8000-00805f9b34fb'; // Common generic service
        this.characteristicUuid = '00002af1-0000-1000-8000-00805f9b34fb'; // Common generic characteristic
        
        // Alternatives
        this.altServiceUuid = '49535343-fe7d-4ae5-8fa9-9fafd205e455'; 
        this.altCharacteristicUuid = '49535343-1e4d-4bd9-ba61-23c647249616';
        this.altServiceUuid2 = 'e7810a71-73ae-499d-8c15-faa9aef0c3f2';
        this.altCharacteristicUuid2 = 'bef8d6c9-9c21-4c9e-b632-bd58c1009f9f';
        
        // Maximum MTU payload for typical BLE (20 bytes per chunk)
        this.maxChunk = 20; 
    }

    async connect() {
        try {
            console.log('Requesting Bluetooth Device...');
            // Requesting device with common printer services
            this.device = await navigator.bluetooth.requestDevice({
                filters: [
                    { services: [this.primaryServiceUuid] },
                    { services: [this.altServiceUuid] },
                    { services: [this.altServiceUuid2] }
                ],
                optionalServices: [
                    this.primaryServiceUuid, 
                    this.altServiceUuid, 
                    this.altServiceUuid2,
                    '000018f0-0000-1000-8000-00805f9b34fb'
                ]
            });
            
            if (!this.device) {
                throw new Error("Pilih printer dibatalkan");
            }
            
            console.log('Connecting to GATT Server...');
            this.server = await this.device.gatt.connect();
            
            console.log('Getting Service...');
            let service;
            try { service = await this.server.getPrimaryService(this.primaryServiceUuid); } catch(e) {}
            if(!service) try { service = await this.server.getPrimaryService(this.altServiceUuid); } catch(e) {}
            if(!service) try { service = await this.server.getPrimaryService(this.altServiceUuid2); } catch(e) {}
            
            if (!service) {
                throw new Error('Service BLE Printer tidak didukung atau tidak ditemukan.');
            }

            console.log('Getting Characteristic...');
            let characteristics = await service.getCharacteristics();
            // Find the characteristic that supports 'write' or 'writeWithoutResponse'
            this.characteristic = characteristics.find(c => c.properties.write || c.properties.writeWithoutResponse);
            
            if (!this.characteristic) {
                throw new Error('Characteristic untuk print (write) tidak ditemukan.');
            }

            console.log('Bluetooth Printer Connected!');
            return true;
        } catch (error) {
            console.error('Connection failed!', error);
            if (error.name === 'NotFoundError') {
                throw new Error('Dibatalkan atau perangkat tidak ditemukan. (Pastikan printer menyala dan mensupport BLE)');
            } else {
                throw error;
            }
        }
    }

    async disconnect() {
        if (!this.device) return;
        console.log('Disconnecting from Bluetooth Device...');
        if (this.device.gatt.connected) {
            this.device.gatt.disconnect();
        }
    }

    // --- ESC/POS Builder ---
    buildReceiptData(data) {
        // Lebar karakter maksimal printer 58mm normal adalah 32 karakter
        const MAX_CHAR = 32;
        let buffer = [];

        // Commands
        const CMD_INIT = [0x1B, 0x40];
        const CMD_ALIGN_LEFT = [0x1B, 0x61, 0x00];
        const CMD_ALIGN_CENTER = [0x1B, 0x61, 0x01];
        const CMD_ALIGN_RIGHT = [0x1B, 0x61, 0x02];
        const CMD_BOLD_ON = [0x1B, 0x45, 0x01];
        const CMD_BOLD_OFF = [0x1B, 0x45, 0x00];
        const CMD_NEWLINE = [0x0A];
        
        // Helper untuk menambahkan array byte ke buffer utama
        const push = (arr) => { buffer.push(...arr); };
        
        // Helper untuk string ke Uint8Array menggunakan encoder bawaan browser
        const encoder = new TextEncoder();
        
        const unescapeHtml = (str) => {
            if (!str) return '';
            return String(str)
                .replace(/&amp;/g, '&')
                .replace(/&lt;/g, '<')
                .replace(/&gt;/g, '>')
                .replace(/&quot;/g, '"')
                .replace(/&#039;/g, "'");
        };

        const text = (str) => { push(Array.from(encoder.encode(unescapeHtml(str)))); };
        const textLn = (str) => { text(str); push(CMD_NEWLINE); };
        
        const formatRupiah = (number) => {
            return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(number);
        };
        
        // Format text jadi kolom kiri dan kanan (misal: "Barang A      Rp 10.000")
        const formatRow = (left, right) => {
            let spaceLen = MAX_CHAR - left.length - right.length;
            if (spaceLen < 1) spaceLen = 1;
            return left + " ".repeat(spaceLen) + right;
        };
        const formatRowLn = (left, right) => {
            textLn(formatRow(left, right));
        };
        
        const lineDashed = () => { textLn("-".repeat(MAX_CHAR)); };

        // === MULAI FORMAT STRUK ===
        push(CMD_INIT);
        
        // Header
        push(CMD_ALIGN_CENTER);
        push(CMD_BOLD_ON);
        textLn(data.store_name || 'SarungSantriStore');
        push(CMD_BOLD_OFF);
        
        if (data.store_address) {
            let addressLines = data.store_address.split('\n');
            addressLines.forEach(l => textLn(l.trim()));
        }
        if (data.store_phone) textLn("Telp: " + data.store_phone);
        push(CMD_NEWLINE);
        
        // Info Transaksi
        push(CMD_ALIGN_LEFT);
        formatRowLn(data.invoice_number || '-', "Ksr: " + (data.cashier_name || '-'));
        
        let dateStr = "";
        if (data.date) {
            let d = new Date(data.date);
            dateStr = d.toLocaleString('id-ID', {day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit'}).replace(/\./g, ':');
        }
        formatRowLn(dateStr, data.customer_name ? "Plg: " + data.customer_name : "");
        lineDashed();
        
        // Items
        if (data.items && data.items.length > 0) {
            data.items.forEach(item => {
                let name = item.product_name + (item.variation_name ? ' (' + item.variation_name + ')' : '');
                // Jika nama lebih dari 32 karakter, potong
                if (name.length > MAX_CHAR) {
                    textLn(name.substring(0, MAX_CHAR));
                } else {
                    textLn(name);
                }
                let qtyPrice = item.qty + " x " + formatRupiah(item.unit_price);
                formatRowLn(qtyPrice, formatRupiah(item.subtotal));
            });
        }
        lineDashed();
        
        // Totals
        push(CMD_BOLD_ON);
        formatRowLn("TOTAL", formatRupiah(data.grand_total || 0));
        push(CMD_BOLD_OFF);
        
        if (!data.is_debt) {
            formatRowLn("Bayar ("+(data.payment_method || 'Tunai')+")", formatRupiah(data.paid_amount || 0));
            push(CMD_BOLD_ON);
            formatRowLn("Kembalian", formatRupiah(data.change || 0));
            push(CMD_BOLD_OFF);
        } else {
            push(CMD_BOLD_ON);
            formatRowLn("STATUS", "KASBON");
            push(CMD_BOLD_OFF);
        }
        
        // Footer
        push(CMD_NEWLINE);
        push(CMD_ALIGN_CENTER);
        if (data.receipt_footer) {
            let footerLines = data.receipt_footer.split('\n');
            footerLines.forEach(l => textLn(l.trim()));
        }
        
        // Tambahkan space di akhir agar kertas bisa dipotong
        push(CMD_NEWLINE);
        push(CMD_NEWLINE);
        push(CMD_NEWLINE);
        
        return new Uint8Array(buffer);
    }

    // Fungsi pengiriman chunked (BLE memiliki batasan max packet)
    async sendData(dataArray) {
        if (!this.characteristic) throw new Error("Not connected");
        
        console.log("Sending data, total bytes:", dataArray.length);
        
        // Bagi menjadi chunk 20 bytes 
        for (let i = 0; i < dataArray.length; i += this.maxChunk) {
            let chunk = dataArray.slice(i, i + this.maxChunk);
            try {
                if (this.characteristic.properties.writeWithoutResponse) {
                    await this.characteristic.writeValueWithoutResponse(chunk);
                } else {
                    await this.characteristic.writeValue(chunk);
                }
                // Beri sedikit jeda agar printer buffer tidak overflow
                await new Promise(r => setTimeout(r, 10)); 
            } catch (err) {
                console.error("Gagal mengirim chunk", err);
                throw err;
            }
        }
    }

    async printReceipt(data) {
        try {
            if (!this.device || !this.device.gatt.connected) {
                await this.connect();
            }
            const escPosData = this.buildReceiptData(data);
            await this.sendData(escPosData);
            return true;
        } catch (error) {
            throw error;
        }
    }
}

// Inisialisasi global instance
window.btPrinter = new BluetoothPrinter();
