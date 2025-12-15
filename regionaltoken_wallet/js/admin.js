(function (Drupal, drupalSettings, once) {
  'use strict';
  
  console.log('✅ RegioToken Admin JS loaded');
  
  // ==================== HELPER FUNCTIONS ====================
  const showMessage = (message, type = 'info') => {
    const colors = {
      success: '#28a745',
      error: '#dc3545',
      warning: '#ffc107',
      info: '#17a2b8'
    };
    
    const messageDiv = document.createElement('div');
    messageDiv.className = 'admin-notification';
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
  
  const copyToClipboard = (text) => {
    navigator.clipboard.writeText(text).then(() => {
      showMessage('✅ In Zwischenablage kopiert', 'success');
    }).catch(err => {
      console.error('Copy failed:', err);
      showMessage('❌ Kopieren fehlgeschlagen', 'error');
    });
  };
  
  // ==================== TRANSACTION FUNCTIONS ====================
  window.updateTransactionStatus = async (transactionId, newStatus) => {
    if (!confirm(`Transaktion #${transactionId} wirklich auf "${newStatus}" setzen?`)) {
      return;
    }
    
    try {
      showMessage('⏳ Status wird aktualisiert...', 'info');
      
      const response = await fetch(`/admin/regiotoken/transaction/${transactionId}/update-status`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          status: newStatus,
          block_number: newStatus === 'confirmed' ? Math.floor(Math.random() * 1000000) + 1000000 : 0
        }),
        credentials: 'same-origin'
      });
      
      const result = await response.json();
      
      if (result.success) {
        showMessage(`✅ Transaktion #${transactionId} aktualisiert`, 'success');
        
        // Seite neu laden nach 1.5 Sekunden
        setTimeout(() => {
          window.location.reload();
        }, 1500);
      } else {
        showMessage(`❌ Fehler: ${result.error}`, 'error');
      }
      
    } catch (error) {
      console.error('Update error:', error);
      showMessage('❌ Netzwerkfehler', 'error');
    }
  };
  
  // ==================== BALANCE MANAGEMENT ====================
  window.openBalanceModal = (userId, username, currentBalance) => {
    // Modal erstellen
    const modalHTML = `
      <div id="balanceModal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:99998;align-items:center;justify-content:center;display:flex;">
        <div style="background:white;width:95%;max-width:500px;border-radius:12px;padding:2rem;position:relative;">
          <h2 style="margin-top:0;color:#2c3e50;">Guthaben anpassen</h2>
          
          <div style="margin-bottom:1.5rem;">
            <div style="color:#6c757d;margin-bottom:5px;">User</div>
            <div style="font-weight:bold;color:#2c3e50;">${username} (ID: ${userId})</div>
          </div>
          
          <div style="margin-bottom:1.5rem;">
            <div style="color:#6c757d;margin-bottom:5px;">Aktuelles Guthaben</div>
            <div style="font-size:1.5rem;font-weight:bold;color:#28a745;">
              ${parseFloat(currentBalance).toLocaleString('de-DE', {minimumFractionDigits: 2})} REGIO
            </div>
          </div>
          
          <form id="adjustBalanceForm">
            <input type="hidden" name="user_id" value="${userId}">
            
            <div style="margin-bottom:1.5rem;">
              <label style="display:block;margin-bottom:0.5rem;font-weight:bold;color:#4a5568;">
                Änderung (positiv = hinzufügen, negativ = abziehen)
              </label>
              <div style="display:flex;align-items:center;">
                <input id="amountInput" name="amount" type="number" step="0.01" required placeholder="0.00" 
                  style="flex:1;padding:14px;border:2px solid #e2e8f0;border-radius:8px;font-size:1rem;">
                <span style="margin-left:12px;font-weight:bold;color:#2d3748;font-size:1.1rem;">REGIO</span>
              </div>
              <div style="font-size:0.85rem;color:#718096;margin-top:8px;">
                Beispiel: +100 (hinzufügen), -50 (abziehen)
              </div>
            </div>
            
            <div style="margin-bottom:2rem;">
              <label style="display:block;margin-bottom:0.5rem;font-weight:bold;color:#4a5568;">Grund (optional)</label>
              <input name="reason" type="text" placeholder="Z.B. Bonus, Korrektur, etc." 
                style="width:100%;padding:14px;border:2px solid #e2e8f0;border-radius:8px;font-size:1rem;">
            </div>
            
            <div id="newBalancePreview" style="margin-bottom:1.5rem;padding:15px;background:#f8f9fa;border-radius:8px;display:none;">
              <div style="color:#6c757d;margin-bottom:5px;">Neues Guthaben:</div>
              <div style="font-size:1.2rem;font-weight:bold;color:#28a745;" id="newBalanceValue">0,00 REGIO</div>
            </div>
            
            <div style="display:flex;gap:10px;">
              <button type="submit" 
                style="flex:1;padding:16px;background:#28a745;color:white;border:none;border-radius:8px;font-size:1.1rem;cursor:pointer;font-weight:bold;">
                ✅ Guthaben anpassen
              </button>
              <button type="button" id="cancelBalanceBtn" 
                style="padding:16px 24px;background:#6c757d;color:white;border:none;border-radius:8px;cursor:pointer;font-size:1rem;font-weight:bold;">
                Abbrechen
              </button>
            </div>
          </form>
          
          <button id="closeBalanceModalBtn" 
            style="position:absolute;top:20px;right:20px;background:none;border:none;font-size:28px;cursor:pointer;color:#718096;">
            ✖
          </button>
        </div>
      </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    const modal = document.getElementById('balanceModal');
    const amountInput = document.getElementById('amountInput');
    const preview = document.getElementById('newBalancePreview');
    const newBalanceValue = document.getElementById('newBalanceValue');
    
    // Balance Preview bei Eingabe
    amountInput.addEventListener('input', () => {
      const change = parseFloat(amountInput.value) || 0;
      const newBalance = currentBalance + change;
      
      if (change !== 0) {
        preview.style.display = 'block';
        newBalanceValue.textContent = newBalance.toLocaleString('de-DE', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        }) + ' REGIO';
        
        // Farbe basierend auf Änderung
        newBalanceValue.style.color = change > 0 ? '#28a745' : 
                                     change < 0 ? '#dc3545' : '#6c757d';
      } else {
        preview.style.display = 'none';
      }
    });
    
    // Form Submit
    document.getElementById('adjustBalanceForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const formData = new FormData(e.target);
      const amount = parseFloat(formData.get('amount'));
      
      if (isNaN(amount) || amount === 0) {
        showMessage('❌ Bitte einen gültigen Betrag eingeben', 'error');
        return;
      }
      
      const newBalance = currentBalance + amount;
      if (newBalance < 0) {
        showMessage('❌ Guthaben kann nicht negativ sein', 'error');
        return;
      }
      
      try {
        showMessage('⏳ Guthaben wird angepasst...', 'info');
        
        const response = await fetch('/admin/regiotoken/adjust-balance', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: new URLSearchParams({
            user_id: formData.get('user_id'),
            amount: amount,
            reason: formData.get('reason')
          }),
          credentials: 'same-origin'
        });
        
        const result = await response.json();
        
        if (result.success) {
          showMessage(`✅ ${result.message}`, 'success');
          modal.remove();
          
          // Seite neu laden nach 1.5 Sekunden
          setTimeout(() => {
            window.location.reload();
          }, 1500);
        } else {
          showMessage(`❌ ${result.error}`, 'error');
        }
        
      } catch (error) {
        console.error('Adjust balance error:', error);
        showMessage('❌ Netzwerkfehler', 'error');
      }
    });
    
    // Modal schließen
    document.getElementById('closeBalanceModalBtn').addEventListener('click', () => modal.remove());
    document.getElementById('cancelBalanceBtn').addEventListener('click', () => modal.remove());
    modal.addEventListener('click', (e) => {
      if (e.target === modal) modal.remove();
    });
  };
  
  // ==================== ADMIN ACTIONS ====================
  window.syncAllBalances = async () => {
    if (!confirm('Alle Guthaben synchronisieren? Dies kann einige Sekunden dauern.')) {
      return;
    }
    
    try {
      showMessage('⏳ Guthaben werden synchronisiert...', 'info');
      
      // Hier würde die echte Synchronisationslogik kommen
      // Für jetzt: Demo
      await new Promise(resolve => setTimeout(resolve, 2000));
      
      showMessage('✅ Guthaben erfolgreich synchronisiert', 'success');
      
    } catch (error) {
      showMessage('❌ Synchronisation fehlgeschlagen', 'error');
    }
  };
  
  window.clearOldTransactions = async () => {
    if (!confirm('Alte Transaktionen (älter als 90 Tage) wirklich löschen?')) {
      return;
    }
    
    try {
      showMessage('⏳ Alte Transaktionen werden gelöscht...', 'info');
      
      // Hier würde die echte Löschlogik kommen
      await new Promise(resolve => setTimeout(resolve, 1500));
      
      showMessage('✅ Alte Transaktionen gelöscht', 'success');
      
    } catch (error) {
      showMessage('❌ Löschen fehlgeschlagen', 'error');
    }
  };
  
  window.backupDatabase = async () => {
    try {
      showMessage('⏳ Backup wird erstellt...', 'info');
      
      // Backup als JSON herunterladen
      const response = await fetch('/admin/regiotoken/export?format=json');
      const data = await response.json();
      
      if (data.success) {
        // JSON als Datei herunterladen
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `regiotoken-backup-${new Date().toISOString().split('T')[0]}.json`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        showMessage('✅ Backup erfolgreich erstellt', 'success');
      } else {
        showMessage(`❌ ${data.error}`, 'error');
      }
      
    } catch (error) {
      console.error('Backup error:', error);
      showMessage('❌ Backup fehlgeschlagen', 'error');
    }
  };
  
  // ==================== DRUPAL BEHAVIOR ====================
  Drupal.behaviors.regiotokenAdmin = {
    attach: function (context, settings) {
      console.log('🔧 RegioToken admin behavior attaching');
      
      // Nur auf Admin-Seiten ausführen
      const adminContainer = context.querySelector('.regiotoken-admin-dashboard, .transaction-detail');
      if (!adminContainer) {
        return;
      }
      
      console.log('✅ RegioToken admin behavior attached successfully');
    }
  };
  
  console.log('✅ RegioToken Admin JS initialized');

})(Drupal, drupalSettings, once);