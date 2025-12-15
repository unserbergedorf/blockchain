/**
 * Einfacher Kamera-Scanner für RegioToken Wallet
 */
class CameraQRScanner {
  constructor() {
    this.videoElement = null;
    this.canvasElement = null;
    this.canvasContext = null;
    this.stream = null;
    this.scanning = false;
    this.scanInterval = null;
  }
  
  // Verfügbarkeit prüfen
  isAvailable() {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  }
  
  // Scanner starten
  async startScanning(onSuccess, onError) {
    if (!this.isAvailable()) {
      onError('Kamera-API nicht verfügbar');
      return false;
    }
    
    try {
      // Kamera-Stream starten
      this.stream = await navigator.mediaDevices.getUserMedia({
        video: {
          facingMode: 'environment',
          width: { ideal: 1280 },
          height: { ideal: 720 }
        }
      });
      
      return true;
    } catch (error) {
      console.error('Kamera-Fehler:', error);
      onError(this.getErrorMessage(error));
      return false;
    }
  }
  
  // Fehlermeldung übersetzen
  getErrorMessage(error) {
    switch(error.name) {
      case 'NotAllowedError':
        return 'Kamera-Zugriff wurde verweigert. Bitte Berechtigung erteilen.';
      case 'NotFoundError':
        return 'Keine Kamera gefunden.';
      case 'NotReadableError':
        return 'Kamera wird bereits von einer anderen Anwendung verwendet.';
      case 'OverconstrainedError':
        return 'Die angeforderte Kamera ist nicht verfügbar.';
      default:
        return `Kamera-Fehler: ${error.message}`;
    }
  }
  
  // Video-Element erstellen
  createVideoElement(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return null;
    
    // Video erstellen
    this.videoElement = document.createElement('video');
    this.videoElement.id = 'camera-video';
    this.videoElement.autoplay = true;
    this.videoElement.playsInline = true;
    this.videoElement.style.cssText = `
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 10px;
    `;
    
    // Canvas für Bildanalyse
    this.canvasElement = document.createElement('canvas');
    this.canvasElement.style.display = 'none';
    this.canvasContext = this.canvasElement.getContext('2d');
    
    container.innerHTML = '';
    container.appendChild(this.videoElement);
    document.body.appendChild(this.canvasElement);
    
    return this.videoElement;
  }
  
  // Stream auf Video anwenden
  attachStreamToVideo() {
    if (this.videoElement && this.stream) {
      this.videoElement.srcObject = this.stream;
      return new Promise((resolve) => {
        this.videoElement.onloadedmetadata = () => {
          this.videoElement.play();
          resolve();
        };
      });
    }
  }
  
  // Scanner stoppen
  stopScanning() {
    this.scanning = false;
    
    if (this.scanInterval) {
      clearInterval(this.scanInterval);
      this.scanInterval = null;
    }
    
    if (this.stream) {
      this.stream.getTracks().forEach(track => track.stop());
      this.stream = null;
    }
    
    if (this.videoElement) {
      this.videoElement.srcObject = null;
      this.videoElement = null;
    }
    
    if (this.canvasElement) {
      this.canvasElement.remove();
      this.canvasElement = null;
      this.canvasContext = null;
    }
  }
  
  // Einfache QR-Erkennung simulieren (für Demo)
  simulateQRDetection(onSuccess) {
    this.scanning = true;
    
    // Simuliere QR-Erkennung nach zufälliger Zeit
    const scanTime = 1000 + Math.random() * 2000; // 1-3 Sekunden
    
    setTimeout(() => {
      if (!this.scanning) return;
      
      // Zufällige Test-Adresse auswählen
      const testAddresses = [
        '0x4a6a0ff4135ce5a02e3557c1af97637cd204e235',
        '0x742d35Cc6634C0532925a3b844Bc9e9E5e0A962b',
        'ethereum:0x4a6a0ff4135ce5a02e3557c1af97637cd204e235'
      ];
      
      const randomAddress = testAddresses[Math.floor(Math.random() * testAddresses.length)];
      
      // Erfolgreiche Erkennung melden
      onSuccess(randomAddress, {
        decodedText: randomAddress,
        result: { text: randomAddress }
      });
      
    }, scanTime);
  }
}

// Globale Instanz
window.CameraQRScanner = CameraQRScanner;