/**
 * Einfacher QR-Scanner ohne externe Abhängigkeiten
 */

class RegioTokenQRScanner {
    constructor() {
        this.video = null;
        this.stream = null;
        this.scanning = false;
        this.canvas = document.createElement('canvas');
        this.context = this.canvas.getContext('2d');
    }
    
    // Prüfe ob Kamera verfügbar ist
    isCameraAvailable() {
        return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    }
    
    // Scanner Modal öffnen
    async openScanner(onSuccess, onError) {
        if (!this.isCameraAvailable()) {
            onError('Kamera-API nicht verfügbar');
            return;
        }
        
        // Modal erstellen
        const modal = this.createModal();
        document.body.appendChild(modal);
        
        try {
            // Kamera starten
            await this.startCamera();
            
            // Scan-Loop starten
            this.startScanLoop(onSuccess, modal);
            
        } catch (error) {
            onError(error.message);
            modal.remove();
        }
    }
    
    // Modal erstellen
    createModal() {
        const modal = document.createElement('div');
        modal.id = 'regiotoken-qr-scanner';
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.95);
            z-index: 99999;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        `;
        
        modal.innerHTML = `
            <div style="color: white; text-align: center; margin-bottom: 20px;">
                <h2 style="margin: 0 0 10px 0;">📷 QR-Code Scanner</h2>
                <p>Richte die Kamera auf einen RegioToken QR-Code</p>
            </div>
            
            <div style="width: 90%; max-width: 400px; height: 300px; position: relative;">
                <video id="regiotoken-camera" style="width: 100%; height: 100%; border-radius: 10px;"></video>
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
                    width: 200px; height: 200px; border: 3px solid #4CAF50; border-radius: 10px;"></div>
            </div>
            
            <div style="margin-top: 20px;">
                <button id="regiotoken-cancel-scan" style="padding: 10px 20px; background: #f44336; 
                    color: white; border: none; border-radius: 5px; cursor: pointer;">
                    ❌ Abbrechen
                </button>
            </div>
        `;
        
        // Event Listener
        modal.querySelector('#regiotoken-cancel-scan').addEventListener('click', () => {
            this.closeScanner();
            modal.remove();
        });
        
        return modal;
    }
    
    // Kamera starten
    async startCamera() {
        try {
            this.stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'environment',
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                }
            });
            
            this.video = document.getElementById('regiotoken-camera');
            this.video.srcObject = this.stream;
            await this.video.play();
            
        } catch (error) {
            throw new Error(`Kamera-Fehler: ${error.message}`);
        }
    }
    
    // Scan-Loop starten (simuliert)
    startScanLoop(onSuccess, modal) {
        this.scanning = true;
        
        const scanFrame = () => {
            if (!this.scanning || !this.video) return;
            
            try {
                // Hier würde echte QR-Erkennung stattfinden
                // Für Demo: Simuliere Erkennung nach 3 Sekunden
                if (Math.random() < 0.1) { // 10% Chance pro Frame
                    // Simulierte QR-Code Daten
                    const demoAddresses = [
                        '0x4a6a0ff4135ce5a02e3557c1af97637cd204e235',
                        '0x742d35Cc6634C0532925a3b844Bc9e9E5e0A962b',
                        'ethereum:0x4a6a0ff4135ce5a02e3557c1af97637cd204e235'
                    ];
                    
                    const randomAddress = demoAddresses[Math.floor(Math.random() * demoAddresses.length)];
                    
                    // Erfolgreiche Erkennung
                    this.closeScanner();
                    modal.remove();
                    onSuccess(randomAddress);
                    return;
                }
                
                // Nächster Frame
                if (this.scanning) {
                    requestAnimationFrame(scanFrame);
                }
                
            } catch (error) {
                console.error('Scan error:', error);
            }
        };
        
        // Starte Scan-Loop
        scanFrame();
    }
    
    // Scanner schließen
    closeScanner() {
        this.scanning = false;
        
        if (this.stream) {
            this.stream.getTracks().forEach(track => track.stop());
            this.stream = null;
        }
        
        if (this.video) {
            this.video.srcObject = null;
            this.video = null;
        }
    }
}

// Globale Instanz
window.RegioTokenQRScanner = RegioTokenQRScanner;