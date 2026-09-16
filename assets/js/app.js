/**
 * Classic Enzyme IoT - Liquid Glass Dashboard Controller
 * Pure Vanilla JavaScript (Zero-dependency, Chart.js CDN integration)
 */

document.addEventListener('DOMContentLoaded', () => {
    // --------------------------------------------------------------------------
    // 1. STATE & KONFIGURASI
    // --------------------------------------------------------------------------
    const state = {
        currentDeviceId: 'esp32-ce-001',
        theme: localStorage.getItem('ce_theme') || 'dark',
        chartRange: '1h',
        lastSeenTimestamp: null,
        lastTelemetryId: null,      // Pelacak ID terakhir untuk auto-sync instan
        pollIntervalMs: 3000,       // Polling cepat & responsif (3 detik)
        chartInstance: null,
        pollTimer: null,
        secondsTickerTimer: null,
    };

    // DOM Elements
    const el = {
        themeToggleBtn: document.getElementById('themeToggleBtn'),
        themeIcon: document.getElementById('themeIcon'),
        deviceSelect: document.getElementById('deviceSelect'),
        statusPill: document.getElementById('statusPill'),
        statusText: document.getElementById('statusText'),
        statusLastSeen: document.getElementById('statusLastSeen'),
        
        // Metrics
        valTemp: document.getElementById('valTemp'),
        valPh: document.getElementById('valPh'),
        valAlcohol: document.getElementById('valAlcohol'),
        valRssi: document.getElementById('valRssi'),
        valIp: document.getElementById('valIp'),
        valFirmware: document.getElementById('valFirmware'),
        badgeTemp: document.getElementById('badgeTemp'),
        badgePh: document.getElementById('badgePh'),
        badgeAlcohol: document.getElementById('badgeAlcohol'),
        footerTemp: document.getElementById('footerTemp'),
        footerPh: document.getElementById('footerPh'),
        footerAlcohol: document.getElementById('footerAlcohol'),

        // Chart & History
        chartCanvas: document.getElementById('telemetryChart'),
        chartRangeTabs: document.querySelectorAll('.chart-tab-btn'),
        historyTableBody: document.getElementById('historyTableBody'),
        btnRefresh: document.getElementById('btnRefresh'),
        btnSimulate: document.getElementById('btnSimulate'),
        toastContainer: document.getElementById('toastContainer'),
    };

    // --------------------------------------------------------------------------
    // 2. TEMA CERAH / GELAP (LIGHT / DARK MODE)
    // --------------------------------------------------------------------------
    function applyTheme(theme) {
        state.theme = theme;
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('ce_theme', theme);

        if (el.themeIcon) {
            el.themeIcon.textContent = theme === 'dark' ? 'D' : 'L';
        }

        // Perbarui warna grid & font pada Chart.js jika sudah terbentuk
        if (state.chartInstance) {
            updateChartTheme();
        }
    }

    if (el.themeToggleBtn) {
        el.themeToggleBtn.addEventListener('click', () => {
            const nextTheme = state.theme === 'dark' ? 'light' : 'dark';
            applyTheme(nextTheme);
            showToast(`Beralih ke Mode ${nextTheme === 'dark' ? 'Gelap' : 'Cerah'}`);
        });
    }

    // Inisialisasi tema awal
    applyTheme(state.theme);

    // --------------------------------------------------------------------------
    // 3. INISIALISASI GRAFIK TELEMETRI (CHART.JS)
    // --------------------------------------------------------------------------
    function initChart() {
        if (!el.chartCanvas || typeof Chart === 'undefined') return;

        const isDark = state.theme === 'dark';
        const gridColor = isDark ? 'rgba(78, 191, 193, 0.08)' : 'rgba(78, 191, 193, 0.12)';
        const textColor = isDark ? '#7fa8b0' : '#5a7080';

        const ctx = el.chartCanvas.getContext('2d');

        // Gradient untuk Suhu (Pink #de539d)
        const gradTemp = ctx.createLinearGradient(0, 0, 0, 300);
        gradTemp.addColorStop(0, 'rgba(222, 83, 157, 0.22)');
        gradTemp.addColorStop(1, 'rgba(222, 83, 157, 0.0)');

        // Gradient untuk pH (Teal #4ebfc1)
        const gradPh = ctx.createLinearGradient(0, 0, 0, 300);
        gradPh.addColorStop(0, 'rgba(78, 191, 193, 0.22)');
        gradPh.addColorStop(1, 'rgba(78, 191, 193, 0.0)');

        state.chartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    {
                        label: 'Suhu (°C)',
                        yAxisID: 'yTemp',
                        data: [],
                        borderColor: '#de539d',
                        backgroundColor: gradTemp,
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.38,
                        pointRadius: 3,
                        pointBackgroundColor: '#de539d',
                        pointHoverRadius: 6,
                    },
                    {
                        label: 'pH',
                        yAxisID: 'yPh',
                        data: [],
                        borderColor: '#4ebfc1',
                        backgroundColor: gradPh,
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.38,
                        pointRadius: 3,
                        pointBackgroundColor: '#4ebfc1',
                        pointHoverRadius: 6,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            color: textColor,
                            font: { family: 'Outfit', weight: '600', size: 12 },
                            usePointStyle: true,
                            boxWidth: 8,
                            padding: 16,
                        }
                    },
                    tooltip: {
                        backgroundColor: isDark ? 'rgba(15, 22, 35, 0.90)' : 'rgba(255, 255, 255, 0.96)',
                        titleColor: isDark ? '#f0f8f8' : '#1a2332',
                        bodyColor: isDark ? '#7fa8b0' : '#5a7080',
                        borderColor: isDark ? 'rgba(255,255,255,0.10)' : 'rgba(0,0,0,0.08)',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 12,
                        titleFont: { family: 'Outfit', weight: '700', size: 13 },
                        bodyFont: { family: 'JetBrains Mono', size: 12 },
                    }
                },
                scales: {
                    x: {
                        grid: { color: gridColor },
                        ticks: { color: textColor, font: { family: 'JetBrains Mono', size: 10 } }
                    },
                    yTemp: {
                        type: 'linear',
                        position: 'left',
                        grid: { color: gridColor },
                        title: { display: true, text: 'Suhu (°C)', color: '#de539d', font: { family: 'Outfit', weight: '600' } },
                        ticks: { color: textColor, font: { family: 'JetBrains Mono', size: 10 } }
                    },
                    yPh: {
                        type: 'linear',
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        title: { display: true, text: 'pH', color: '#4ebfc1', font: { family: 'Outfit', weight: '600' } },
                        ticks: { color: textColor, font: { family: 'JetBrains Mono', size: 10 } }
                    }
                }
            }
        });
    }

    function updateChartTheme() {
        if (!state.chartInstance) return;
        const isDark = state.theme === 'dark';
        const gridColor = isDark ? 'rgba(78, 191, 193, 0.08)' : 'rgba(78, 191, 193, 0.12)';
        const textColor = isDark ? '#7fa8b0' : '#5a7080';
        const chart = state.chartInstance;

        chart.options.scales.x.grid.color = gridColor;
        chart.options.scales.x.ticks.color = textColor;
        chart.options.scales.yTemp.grid.color = gridColor;
        chart.options.scales.yTemp.ticks.color = textColor;
        chart.options.scales.yPh.ticks.color = textColor;
        chart.options.plugins.legend.labels.color = textColor;
        chart.options.plugins.tooltip.backgroundColor = isDark ? 'rgba(15,22,35,0.90)' : 'rgba(255,255,255,0.96)';
        chart.options.plugins.tooltip.titleColor = isDark ? '#f0f8f8' : '#1a2332';
        chart.options.plugins.tooltip.bodyColor = isDark ? '#7fa8b0' : '#5a7080';
        chart.update('none');
    }

    // --------------------------------------------------------------------------
    // 4. TICKER HITUNGAN DETIK RELATIF ("TERAKHIR DITERIMA: X DETIK LALU")
    // --------------------------------------------------------------------------
    function updateRelativeTimeCounter() {
        if (!state.lastSeenTimestamp || !el.statusLastSeen) return;

        const now = Math.floor(Date.now() / 1000);
        const diff = Math.max(0, now - state.lastSeenTimestamp);

        let label = '';
        if (diff < 5) {
            label = 'Baru saja';
        } else if (diff < 60) {
            label = `${diff} detik lalu`;
        } else if (diff < 3600) {
            label = `${Math.floor(diff / 60)} menit lalu`;
        } else {
            label = `${Math.floor(diff / 3600)} jam lalu`;
        }

        el.statusLastSeen.textContent = `Terakhir: ${label}`;

        // Jika lebih dari 30 detik tanpa data, ubah badge menjadi OFFLINE secara dinamis
        if (diff > 30) {
            if (el.statusPill) {
                el.statusPill.classList.remove('online');
                el.statusPill.classList.add('offline');
            }
            if (el.statusText) el.statusText.textContent = 'OFFLINE';
        } else {
            if (el.statusPill) {
                el.statusPill.classList.remove('offline');
                el.statusPill.classList.add('online');
            }
            if (el.statusText) el.statusText.textContent = 'ONLINE';
        }
    }

    // Helper trigger animasi flash angka berubah
    function pulseOnChange(element, newValue) {
        if (!element) return;
        if (element.textContent !== newValue) {
            element.textContent = newValue;
            element.classList.remove('val-updated');
            // Trigger reflow to restart animation
            void element.offsetWidth;
            element.classList.add('val-updated');
        }
    }

    // --------------------------------------------------------------------------
    // 5. FETCH DATA REALTIME DARI API (POLLING)
    // --------------------------------------------------------------------------
    async function fetchLatestData() {
        try {
            const res = await fetch(`api/latest.php?device_id=${encodeURIComponent(state.currentDeviceId)}`);
            const json = await res.json();

            if (json.status === 'ok' && json.device) {
                const dev = json.device;
                const tel = json.telemetry;

                // Simpan timestamp last seen
                if (dev.last_seen) {
                    state.lastSeenTimestamp = Math.floor(new Date(dev.last_seen).getTime() / 1000);
                    updateRelativeTimeCounter();
                }

                // Helper format rentang ideal
                function formatThresholdRange(thresh, unit, fallback) {
                    if (!thresh) return fallback;
                    const hasMin = thresh.min !== null && thresh.min !== undefined;
                    const hasMax = thresh.max !== null && thresh.max !== undefined;
                    if (hasMin && hasMax) return `${thresh.min} – ${thresh.max}${unit ? ' ' + unit : ''}`;
                    if (hasMin) return `≥ ${thresh.min}${unit ? ' ' + unit : ''}`;
                    if (hasMax) return `≤ ${thresh.max}${unit ? ' ' + unit : ''}`;
                    return fallback;
                }

                // Update Metric Display & Dynamic Ideal Ranges
                const th = json.thresholds || {};

                if (tel) {
                    // Deteksi jika ada rekaman baru masuk: langsung refresh grafik & tabel tanpa delay!
                    const isNewRecord = (state.lastTelemetryId !== null && tel.id !== state.lastTelemetryId);
                    state.lastTelemetryId = tel.id;

                    if (el.valTemp) {
                        const newTemp = tel.temperature !== null ? tel.temperature.toFixed(2) : '--';
                        pulseOnChange(el.valTemp, newTemp);
                    }
                    if (el.badgeTemp && el.footerTemp) {
                        if (tel.temperature !== null) {
                            const isWarn = (th.temp?.max !== null && tel.temperature > th.temp?.max) || 
                                           (th.temp?.min !== null && tel.temperature < th.temp?.min);
                            el.badgeTemp.textContent = isWarn ? 'ALERT' : 'TEMP';
                            el.badgeTemp.className = isWarn ? 'sensor-badge badge-warning' : 'sensor-badge badge-pink';
                            el.footerTemp.textContent = formatThresholdRange(th.temp, '°C', '30 – 38 °C');
                        } else {
                            el.badgeTemp.textContent = 'OFF';
                            el.badgeTemp.className = 'sensor-badge badge-slate';
                            el.footerTemp.textContent = 'Belum Terpasang';
                        }
                    }

                    if (el.valPh) {
                        const newPh = tel.ph !== null ? tel.ph.toFixed(2) : '--';
                        pulseOnChange(el.valPh, newPh);
                    }
                    if (el.badgePh && el.footerPh) {
                        if (tel.ph !== null) {
                            const isWarn = (th.ph?.max !== null && tel.ph > th.ph?.max) || 
                                           (th.ph?.min !== null && tel.ph < th.ph?.min);
                            el.badgePh.textContent = isWarn ? 'ALERT' : 'pH';
                            el.badgePh.className = isWarn ? 'sensor-badge badge-warning' : 'sensor-badge badge-teal';
                            el.footerPh.textContent = formatThresholdRange(th.ph, 'pH', '3.2 – 4.5');
                        } else {
                            el.badgePh.textContent = 'OFF';
                            el.badgePh.className = 'sensor-badge badge-slate';
                            el.footerPh.textContent = 'Belum Terpasang';
                        }
                    }

                    if (el.valAlcohol) {
                        const newAlcohol = tel.alcohol !== null ? tel.alcohol.toFixed(1) : '--';
                        pulseOnChange(el.valAlcohol, newAlcohol);
                    }
                    if (el.badgeAlcohol && el.footerAlcohol) {
                        if (tel.alcohol !== null) {
                            const isWarn = (th.alcohol?.max !== null && tel.alcohol > th.alcohol?.max);
                            el.badgeAlcohol.textContent = isWarn ? 'ALERT' : 'GAS';
                            el.badgeAlcohol.className = isWarn ? 'sensor-badge badge-warning' : 'sensor-badge badge-violet';
                            el.footerAlcohol.textContent = formatThresholdRange(th.alcohol, 'ADC', 'Anaerob');
                        } else {
                            el.badgeAlcohol.textContent = 'OFF';
                            el.badgeAlcohol.className = 'sensor-badge badge-slate';
                            el.footerAlcohol.textContent = 'Belum Terpasang';
                        }
                    }

                    if (el.valRssi) {
                        const rssiVal = tel.rssi !== null ? tel.rssi : null;
                        const newRssi = rssiVal !== null ? `${rssiVal}` : '--';
                        pulseOnChange(el.valRssi, newRssi);

                        // Update status chip with signal quality
                        const chip = document.getElementById('statusChip');
                        if (chip) {
                            if (rssiVal === null) { chip.textContent = '—'; chip.style.background = ''; }
                            else if (rssiVal >= -60) { chip.textContent = 'Sangat Baik'; chip.style.background = 'var(--teal)'; chip.style.color = '#000'; }
                            else if (rssiVal >= -70) { chip.textContent = 'Baik'; chip.style.background = 'var(--teal)'; chip.style.color = '#000'; }
                            else if (rssiVal >= -80) { chip.textContent = 'Lemah'; chip.style.background = 'var(--amber)'; chip.style.color = '#000'; }
                            else { chip.textContent = 'Kritis'; chip.style.background = 'var(--danger)'; chip.style.color = '#fff'; }
                        }
                    }
                    if (el.valFirmware) {
                        el.valFirmware.textContent = `v${tel.firmware_ver || '1.0.0'}`;
                    }

                    // Jika ada data baru dari ESP32, LANGSUNG refresh grafik & tabel seketika!
                    if (isNewRecord) {
                        fetchHistoryData();
                    }
                }
            }
        } catch (err) {
            console.warn('Gagal mengambil data terbaru:', err);
        }
    }

    // --------------------------------------------------------------------------
    // 6. FETCH HISTORI DATA (UNTUK GRAFIK & TABEL LOG)
    // --------------------------------------------------------------------------
    async function fetchHistoryData() {
        try {
            const res = await fetch(`api/history.php?device_id=${encodeURIComponent(state.currentDeviceId)}&range=${state.chartRange}&limit=40`);
            const json = await res.json();

            if (json.status === 'ok' && Array.isArray(json.data)) {
                updateChartData(json.data);
                updateHistoryTable(json.data);
            }
        } catch (err) {
            console.warn('Gagal memuat histori grafik:', err);
        }
    }

    function updateChartData(records) {
        if (!state.chartInstance) return;

        const labels = [];
        const tempData = [];
        const phData = [];

        records.forEach(row => {
            labels.push(row.time);
            tempData.push(row.temperature);
            phData.push(row.ph);
        });

        state.chartInstance.data.labels = labels;
        state.chartInstance.data.datasets[0].data = tempData;
        state.chartInstance.data.datasets[1].data = phData;
        state.chartInstance.update('none');
    }

    function updateHistoryTable(records) {
        if (!el.historyTableBody) return;

        if (records.length === 0) {
            el.historyTableBody.innerHTML = `
                <tr>
                    <td colspan="6" style="text-align:center; color:var(--text-muted); padding:28px;">
                        Belum ada rekaman data telemetri. Kirimkan data pertama via ESP32 atau tombol Simulasi.
                    </td>
                </tr>
            `;
            return;
        }

        // Render 10 baris terakhir dalam urutan menurun (terbaru di atas)
        const recentRows = [...records].reverse().slice(0, 10);

        el.historyTableBody.innerHTML = recentRows.map(row => `
            <tr>
                <td>
                    <strong>${escapeHtml(row.time)}</strong>
                    <span style="font-size:0.68rem; color:var(--teal); margin-left:5px; background:var(--teal-soft); padding:1px 6px; border-radius:4px;">${escapeHtml(row.relative_time || 'Baru saja')}</span>
                </td>
                <td><span style="color:var(--pink); font-weight:700;">${row.temperature !== null ? row.temperature + ' °C' : '--'}</span></td>
                <td><span style="color:var(--teal); font-weight:700;">${row.ph !== null ? row.ph : '--'}</span></td>
                <td><span style="color:var(--violet); font-weight:700;">${row.alcohol !== null ? row.alcohol : '--'}</span></td>
                <td>${row.rssi !== null ? row.rssi + ' dBm' : '--'}</td>
                <td><span class="sensor-badge badge-teal" style="font-size:0.65rem;">VALID</span></td>
            </tr>
        `).join('');
    }

    // --------------------------------------------------------------------------
    // 7. EVENT LISTENERS & KONTROL INTERAKTIF
    // --------------------------------------------------------------------------
    
    // Switch Device
    if (el.deviceSelect) {
        el.deviceSelect.addEventListener('change', (e) => {
            state.currentDeviceId = e.target.value;
            fetchLatestData();
            fetchHistoryData();
            showToast(`Beralih ke perangkat: ${state.currentDeviceId}`);
        });
    }

    // Tab Rentang Waktu Chart (1h, 6h, 24h, all)
    el.chartRangeTabs.forEach(btn => {
        btn.addEventListener('click', (e) => {
            el.chartRangeTabs.forEach(b => b.classList.remove('active'));
            e.currentTarget.classList.add('active');
            state.chartRange = e.currentTarget.getAttribute('data-range') || '1h';
            fetchHistoryData();
        });
    });

    // Tombol Refresh Manual
    if (el.btnRefresh) {
        el.btnRefresh.addEventListener('click', () => {
            fetchLatestData();
            fetchHistoryData();
            showToast('Memperbarui data telemetri...');
        });
    }

    // Tombol Simulasi Kirim Data ESP32 (Testing tanpa hardware)
    if (el.btnSimulate) {
        el.btnSimulate.addEventListener('click', async () => {
            try {
                // Generate data sensor acak realistis
                // Suhu fermentasi normal: 32 - 37°C
                const simTemp = (33.0 + Math.random() * 4.5).toFixed(2);
                // pH asam Classic Enzyme normal: 3.4 - 4.2
                const simPh = (3.4 + Math.random() * 0.8).toFixed(2);
                // Nilai alkohol MQ-3: 80 - 180
                const simAlcohol = Math.floor(90 + Math.random() * 110);
                const simRssi = -Math.floor(55 + Math.random() * 20);

                const payload = {
                    device_id: state.currentDeviceId,
                    api_key: 'ce-secret-key-001',
                    temperature: parseFloat(simTemp),
                    ph: parseFloat(simPh),
                    alcohol: simAlcohol,
                    raw_temp: 14800,
                    raw_adc: simAlcohol,
                    rssi: simRssi,
                    firmware: '1.0.0',
                    ts: Math.floor(Date.now() / 1000)
                };

                const res = await fetch('api/telemetry.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const json = await res.json();
                if (json.status === 'ok') {
                    showToast(`Simulasi ESP32 terkirim: Suhu ${simTemp}°C, pH ${simPh}`);
                    fetchLatestData();
                    fetchHistoryData();
                } else {
                    showToast(`Gagal: ${json.message}`, 'error');
                }
            } catch (err) {
                showToast('Error koneksi simulasi', 'error');
            }
        });
    }

    // Helper Toast Notification
    function showToast(message, type = 'info') {
        if (!el.toastContainer) return;
        const toast = document.createElement('div');
        toast.className = 'glass-toast glass';
        toast.style.borderColor = type === 'error' ? 'var(--pink)' : 'var(--teal)';
        toast.innerHTML = `<span>${escapeHtml(message)}</span>`;
        el.toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3200);
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // --------------------------------------------------------------------------
    // 8. STARTUP & POLLING CYCLE
    // --------------------------------------------------------------------------
    initChart();
    fetchLatestData();
    fetchHistoryData();

    // Polling data realtime dari server tiap 3 detik
    state.pollTimer = setInterval(fetchLatestData, state.pollIntervalMs);

    // Refresh grafik & tabel otomatis tiap 6 detik (fallback jika tidak ada push baru)
    setInterval(fetchHistoryData, 6000);

    // Ticker hitungan detik update tiap 1 detik
    state.secondsTickerTimer = setInterval(updateRelativeTimeCounter, 1000);

    // Auto-sync instan saat user membuka kembali tab browser (misal setelah switch tab)
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            fetchLatestData();
            fetchHistoryData();
        }
    });
});
