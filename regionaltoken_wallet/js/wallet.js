(function (Drupal, drupalSettings, once) {
  'use strict';
  
  console.log('✅ RegioToken Wallet JS loaded');
  
  // ==================== HELPER FUNCTIONS ====================
  const showMessage = (message, type = 'info') => {
    const colors = {
      success: '#4CAF50',
      error: '#f44336',
      warning: '#FF9800',
      info: '#2196F3'
    };
    
    // Alte Nachrichten entfernen
    document.querySelectorAll('.wallet-notification').forEach(el => el.remove());
    
    const messageDiv = document.createElement('div');
    messageDiv.className = 'wallet-notification';
    messageDiv.textContent = message;
    messageDiv.style.cssText = `
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 15px 20px;
      background: ${colors[type] || colors.info};
      color: white;
      border-radius: 8px;
      box-shadow: 0 5px 15px rgba(0,0,0,0.2);
      z-index: 100000;
      max-width: 300px;
      animation: slideIn 0.3s ease-out;
    `;
    
    document.body.appendChild(messageDiv);
    
    setTimeout(() => {
      messageDiv.style.opacity = '0';
      messageDiv.style.transition = 'opacity 0.5s';
      setTimeout(() => messageDiv.remove(), 500);
    }, 3000);
  };
  
  const validateEthereumAddress = (address) => {
    return /^0x[a-fA-F0-9]{40}$/.test(address);
  };
  
  // ==================== MODAL FUNCTIONS ====================
  const openSendModal = () => {
    console.log('Opening send modal');
    
    if (!document.getElementById('sendModal')) {
      createModal();
    }
    
    const modal = document.getElementById('sendModal');
    if (modal) {
      modal.style.display = 'flex';
      // QR-Scanner Button hinzufügen
      setTimeout(() => addScannerButton(), 10);
    }
  };
  
  const closeSendModal = () => {
    const modal = document.getElementById('sendModal');
    if (modal) modal.style.display = 'none';
  };
  
  // ==================== QR-SCANNER FUNCTIONS ====================
  const addScannerButton = () => {
    const addressContainer = document.getElementById('addressContainer');
    const addressInput = addressContainer?.querySelector('input[name="address"]');
    
    if (addressInput && !addressContainer.querySelector('.scan-btn')) {
      console.log('Adding scanner button');
      
      const scannerBtn = document.createElement('button');
      scannerBtn.type = 'button';
      scannerBtn.className = 'scan-btn';
      scannerBtn.innerHTML = '📷 Scan QR';
      scannerBtn.title = 'QR-Code für RegioToken Adresse scannen';
      scannerBtn.style.cssText = `
        padding: 12px 20px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        white-space: nowrap;
        font-size: 0.95rem;
        margin-left: 10px;
        font-weight: bold;
      `;
      
      scannerBtn.addEventListener('click', (e) => {
        e.preventDefault();
        openManualScanner(addressInput);
      });
      
      addressContainer.appendChild(scannerBtn);
    }
  };
  
  const openManualScanner = (addressInput) => {
    const address = prompt(
      '🔗 RegioToken Adresse eingeben:\n\n' +
      'Format: 0x gefolgt von 40 Zeichen\n' +
      'Beispiel: 0x4a6a0ff4135ce5a02e3557c1af97637cd204e235\n\n' +
      'Adresse:',
      ''
    );
    
    if (address) {
      processScannedAddress(address, addressInput);
    }
  };
  
  const processScannedAddress = (address, addressInput) => {
    const cleanAddress = address.trim().toLowerCase();
    
    // Direkte Ethereum Adresse
    if (/^0x[a-f0-9]{40}$/.test(cleanAddress)) {
      addressInput.value = cleanAddress;
      showMessage('✅ RegioToken Adresse erkannt!', 'success');
      addressInput.dispatchEvent(new Event('input', { bubbles: true }));
      return;
    }
    
    // Ethereum URI
    if (cleanAddress.startsWith('ethereum:')) {
      const extracted = cleanAddress.replace('ethereum:', '').split('?')[0];
      if (/^0x[a-f0-9]{40}$/.test(extracted)) {
        addressInput.value = extracted;
        showMessage('✅ Ethereum URI erkannt!', 'success');
        addressInput.dispatchEvent(new Event('input', { bubbles: true }));
        return;
      }
    }
    
    // Ungültiges Format
    showMessage('❌ Bitte prüfen: Format sollte 0x... (40 hex Zeichen) sein', 'error');
    addressInput.value = cleanAddress;
    addressInput.focus();
    addressInput.select();
  };
  
  // ==================== TRANSFER FUNCTIONS ====================
  const submitTokenTransfer = async (formData) => {
    console.log('Submitting token transfer:', formData);
    
    try {
      showMessage('⏳ RegioToken Transaktion wird verarbeitet...', 'info');
      
      // Transfer-URL
      const transferUrl = '/rtw-transfer';
      
      const response = await fetch(transferUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams(formData),
        credentials: 'same-origin'
      });
      
      const result = await response.json();
      console.log('Transfer result:', result);
      
      if (result.success) {
        showMessage('✅ ' + result.message, 'success');
        closeSendModal();
        
        // Guthaben aktualisieren
        updateBalanceAfterTransfer(result.amount, result.newBalance);
        
        // Transaktion zur Liste hinzufügen
        addTransactionToList(result);
        
        // Formular zurücksetzen
        document.getElementById('tokenSendForm')?.reset();
      } else {
        showMessage('❌ ' + result.error, 'error');
      }
      
    } catch (error) {
      console.error('Transfer error:', error);
      showMessage('❌ Netzwerkfehler: ' + error.message, 'error');
    }
  };
  
  const updateBalanceAfterTransfer = (amountSent, newBalance) => {
    const balanceElement = document.querySelector('.balance-amount');
    if (balanceElement) {
      balanceElement.textContent = newBalance.toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }
  };
  
  const addTransactionToList = (transaction) => {
    const transactionsContainer = document.querySelector('.regiotoken-wallet');
    if (!transactionsContainer) return;
    
    // Finde oder erstelle Transaktionsliste
    let transactionsList = transactionsContainer.querySelector('.transactions-list');
    
    if (!transactionsList) {
      const transactionsSection = transactionsContainer.querySelector('div:last-child');
      if (!transactionsSection) return;
      
      transactionsList = document.createElement('div');
      transactionsList.className = 'transactions-list';
      transactionsList.style.cssText = `
        background: #f8f9fa;
        padding: 20px;
        border-radius: 8px;
        margin-top: 1rem;
      `;
      
      const heading = transactionsSection.querySelector('h3');
      if (heading) {
        heading.parentNode.insertBefore(transactionsList, heading.nextSibling);
      }
    }
    
    // Transaktionselement erstellen
    const transactionElement = document.createElement('div');
    transactionElement.style.cssText = `
      display: flex;
      justify-content: space-between;
      padding: 12px 0;
      border-bottom: 1px solid #dee2e6;
    `;
    
    const currentUserAddress = drupalSettings.regiotoken?.walletAddress || '';
    const isSent = transaction.from === currentUserAddress;
    
    const date = new Date(transaction.timestamp * 1000);
    const formattedDate = date.toLocaleDateString('de-DE', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
    
    transactionElement.innerHTML = `
      <div style="flex:1;">
        <div style="font-weight:500;display:flex;align-items:center;gap:8px;">
          <span style="color:${isSent ? '#f44336' : '#4CAF50'}">
            ${isSent ? '➡️ Gesendet' : '⬅️ Empfangen'}
          </span>
          <span style="font-size:0.75rem;padding:2px 8px;background:#e9ecef;border-radius:10px;color:#495057;">
            confirmed
          </span>
        </div>
        <div style="font-size:0.85rem;color:#6c757d;margin-top:4px;">
          ${formattedDate}
          ${transaction.memo ? ` - ${transaction.memo}` : ''}
        </div>
        <div style="font-size:0.75rem;color:#adb5bd;margin-top:2px;font-family:monospace;">
          ${transaction.transactionHash?.substring(0, 12)}...${transaction.transactionHash?.substring(transaction.transactionHash?.length - 8)}
        </div>
      </div>
      <div style="font-weight:bold;color:${isSent ? '#f44336' : '#4CAF50'};font-size:1.1rem;">
        ${isSent ? '-' : '+'}${parseFloat(transaction.amount).toLocaleString('de-DE', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        })} ${transaction.symbol || 'REGIO'}
      </div>
    `;
    
    // Neue Transaktion oben einfügen
    transactionsList.insertBefore(transactionElement, transactionsList.firstChild);
    
    // "Keine Transaktionen" Meldung entfernen falls vorhanden
    const emptyState = transactionsContainer.querySelector('.empty-state');
    if (emptyState) {
      emptyState.remove();
    }
  };
  
  // ==================== MODAL CREATION ====================
  const createModal = () => {
    const modalHTML = `
      <div id="sendModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:99998;align-items:center;justify-content:center;">
        <div style="background:white;width:95%;max-width:500px;border-radius:12px;padding:2rem;position:relative;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
          <h2 style="margin-top:0;color:#2c3e50;">RegioToken senden</h2>
          
          <form id="tokenSendForm" method="post" style="display:block;">
            <input type="hidden" name="form_token" value="${drupalSettings.regiotoken?.token || ''}">
            
            <div style="margin-bottom:1.5rem;">
              <label style="display:block;margin-bottom:0.5rem;font-weight:bold;color:#4a5568;">
                <span style="color:#e53e3e;">*</span> Empfänger-Adresse
              </label>
              <div id="addressContainer" style="display:flex;gap:10px;align-items:center;">
                <input name="address" type="text" placeholder="0x..." required 
                  style="flex:1;padding:14px;border:2px solid #e2e8f0;border-radius:8px;font-size:1rem;font-family:monospace;">
              </div>
            </div>
            
            <div style="margin-bottom:1.5rem;">
              <label style="display:block;margin-bottom:0.5rem;font-weight:bold;color:#4a5568;">
                <span style="color:#e53e3e;">*</span> Betrag
              </label>
              <div style="display:flex;align-items:center;">
                <input name="amount" type="number" min="0.01" step="0.01" required placeholder="0.00" 
                  style="flex:1;padding:14px;border:2px solid #e2e8f0;border-radius:8px;font-size:1rem;">
                <span style="margin-left:12px;font-weight:bold;color:#2d3748;font-size:1.1rem;">REGIO</span>
              </div>
              <div style="font-size:0.85rem;color:#718096;margin-top:8px;">
                Verfügbares Guthaben: <span class="modal-balance" style="font-weight:bold;">${document.querySelector('.balance-amount')?.textContent || '0,00'}</span>
              </div>
            </div>
            
            <div style="margin-bottom:2rem;">
              <label style="display:block;margin-bottom:0.5rem;font-weight:bold;color:#4a5568;">Nachricht (optional)</label>
              <input name="memo" type="text" placeholder="Z.B. Für regionale Projekte" 
                style="width:100%;padding:14px;border:2px solid #e2e8f0;border-radius:8px;font-size:1rem;">
            </div>
            
            <div style="display:flex;gap:10px;">
              <button type="submit" 
                style="flex:1;padding:16px;background:linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);color:white;border:none;border-radius:8px;font-size:1.1rem;cursor:pointer;font-weight:bold;">
                ✅ RegioToken senden
              </button>
              <button type="button" id="cancelSendBtn" 
                style="padding:16px 24px;background:#718096;color:white;border:none;border-radius:8px;cursor:pointer;font-size:1rem;font-weight:bold;">
                Abbrechen
              </button>
            </div>
          </form>
          
          <button id="closeModalBtn" 
            style="position:absolute;top:20px;right:20px;background:none;border:none;font-size:28px;cursor:pointer;color:#718096;">
            ✖
          </button>
        </div>
      </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    // Event Listener
    document.getElementById('closeModalBtn').addEventListener('click', closeSendModal);
    document.getElementById('cancelSendBtn').addEventListener('click', closeSendModal);
    
    // Modal Background Click
    document.getElementById('sendModal').addEventListener('click', function(e) {
      if (e.target === this) closeSendModal();
    });
    
    // Form Submit Handler
    document.getElementById('tokenSendForm').addEventListener('submit', async function(e) {
      e.preventDefault();
      
      const addressInput = this.querySelector('input[name="address"]');
      const amountInput = this.querySelector('input[name="amount"]');
      const memoInput = this.querySelector('input[name="memo"]');
      
      const address = addressInput.value.trim();
      const amount = amountInput.value;
      const memo = memoInput.value.trim();
      
      // Parse balance
      const balanceText = document.querySelector('.balance-amount')?.textContent;
      if (!balanceText) {
        showMessage('❌ Guthaben konnte nicht geladen werden', 'error');
        return;
      }
      
      const balance = parseFloat(balanceText.replace(/\./g, '').replace(',', '.'));
      
      if (!validateEthereumAddress(address)) {
        showMessage('❌ Bitte gültige RegioToken Adresse eingeben (0x...)', 'error');
        addressInput.focus();
        return;
      }
      
      if (!amount || isNaN(parseFloat(amount)) || parseFloat(amount) <= 0) {
        showMessage('❌ Bitte gültigen Betrag eingeben', 'error');
        amountInput.focus();
        return;
      }
      
      if (parseFloat(amount) > balance) {
        showMessage(`❌ Betrag übersteigt Ihr Guthaben von ${balance.toFixed(2)} REGIO`, 'error');
        amountInput.focus();
        return;
      }
      
      // Formulardaten
      const formData = {
        address: address,
        amount: amount,
        memo: memo,
        form_token: this.querySelector('input[name="form_token"]').value
      };
      
      // AJAX Request
      await submitTokenTransfer(formData);
    });
  };
  
  // ==================== DRUPAL BEHAVIOR ====================
  Drupal.behaviors.regiotokenWallet = {
    attach: function (context, settings) {
      console.log('🔧 RegioToken wallet behavior attaching');
      
      // Nur auf der Wallet-Seite ausführen
      const walletContainer = context.querySelector('.regiotoken-wallet');
      if (!walletContainer) {
        return;
      }
      
      // 1. Event Listener für Send Button
      once('send-button', '#sendTokensBtn', context).forEach(btn => {
        btn.addEventListener('click', openSendModal);
        console.log('✅ Send button listener added');
      });
      
      // 2. Modal erstellen (falls nicht vorhanden)
      if (!document.getElementById('sendModal')) {
        createModal();
      }
      
      console.log('✅ RegioToken wallet behavior attached successfully');
    }
  };

  // Globale Funktionen
  window.openSendModal = openSendModal;
  window.closeSendModal = closeSendModal;
  
  console.log('✅ RegioToken Wallet JS initialized');

})(Drupal, drupalSettings, once);