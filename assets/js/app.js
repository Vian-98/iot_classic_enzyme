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
        offlineTimeout: 300,        // Default 5 menit (disinkronkan dari database)
        deviceStatusFilter: 'all',  // 'all' | 'online' | 'offline'
        deviceMap: {},              // { device_id: { is_online, device_name } }
        tableFilter: 'all',         // 'all' | 'valid' | 'offline'
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
        filterDeviceBtns: document.querySelectorAll('#deviceStatusFilter .filter-status-btn'),
        filterTableBtns: document.querySelectorAll('#realtimeTableFilter .filter-status-btn'),
    };

    // --------------------------------------------------------------------------
    // 2. TEMA CERAH / GELAP (LIGHT / DARK MODE)
    // --------------------------------------------------------------------------
    function applyTheme(theme) {
        state.theme = theme;
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('ce_theme', theme);

        if (el.themeIcon) {
            if (theme === 'dark') {
                // Ikon Bulan (Moon SVG)
                el.themeIcon.innerHTML = '<svg class="icon-theme" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>';
            } else {
                // Ikon Matahari (Sun SVG)
                el.themeIcon.innerHTML = '<svg class="icon-theme" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>';
            }
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

        // Gradient untuk Alkohol (Amber #f59e0b)
        const gradAlcohol = ctx.createLinearGradient(0, 0, 0, 300);
        gradAlcohol.addColorStop(0, 'rgba(245, 158, 11, 0.22)');
        gradAlcohol.addColorStop(1, 'rgba(245, 158, 11, 0.0)');

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
                        spanGaps: false, // Jeda waktu offline tidak disambung garis palsu
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
                        spanGaps: false,
                    },
                    {
                        label: 'Alkohol (ADC)',
                        yAxisID: 'yAlcohol',
                        data: [],
                        borderColor: '#f59e0b',
                        backgroundColor: gradAlcohol,
                        borderWidth: 2.2,
                        fill: true,
                        tension: 0.38,
                        pointRadius: 3,
                        pointBackgroundColor: '#f59e0b',
                        pointHoverRadius: 6,
                        spanGaps: false,
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
                    },
                    yAlcohol: {
                        type: 'linear',
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        title: { display: false, text: 'Alkohol (ADC)', color: '#f59e0b', font: { family: 'Outfit', weight: '600' } },
                        ticks: { color: '#f59e0b', font: { family: 'JetBrains Mono', size: 9 } }
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
        chart.options.scales.yAlcohol.ticks.color = '#f59e0b';
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
        const timeout = state.offlineTimeout || 300;

        // Format durasi waktu ringkas
        function formatDiff(secs) {
            if (secs < 5)   return 'Baru saja';
            if (secs < 60)  return `${secs} detik lalu`;
            if (secs < 3600) return `${Math.floor(secs / 60)} menit lalu`;
            const h = Math.floor(secs / 3600);
            const m = Math.floor((secs % 3600) / 60);
            return m > 0 ? `${h} jam ${m} menit lalu` : `${h} jam lalu`;
        }

        // Format durasi offline saja (tanpa "lalu")
        function formatOfflineDuration(secs) {
            if (secs < 60)  return `${secs} detik`;
            if (secs < 3600) return `${Math.floor(secs / 60)} menit`;
            const h = Math.floor(secs / 3600);
            const m = Math.floor((secs % 3600) / 60);
            return m > 0 ? `${h} jam ${m} menit` : `${h} jam`;
        }

        // Jika melebihi batas toleransi offline
        if (diff > timeout) {
            const offlineDuration = formatOfflineDuration(diff);
            el.statusLastSeen.textContent = `Offline sejak ${formatOfflineDuration(diff)} yang lalu`;
            if (el.statusPill) {
                el.statusPill.classList.remove('online');
                el.statusPill.classList.add('offline');
            }
            if (el.statusText) el.statusText.textContent = `OFFLINE · ${offlineDuration}`;
        } else {
            el.statusLastSeen.textContent = `Terakhir: ${formatDiff(diff)}`;
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

                // Sinkronkan batas toleransi offline dari server
                if (json.offline_timeout_seconds) {
                    state.offlineTimeout = json.offline_timeout_seconds;
                }

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
                        const newAlcohol = tel.alcohol !== null ? Math.round(tel.alcohol) : '--';
                        pulseOnChange(el.valAlcohol, newAlcohol);
                    }
                    if (el.badgeAlcohol && el.footerAlcohol) {
                        if (tel.alcohol !== null) {
                            const alcVal = tel.alcohol;
                            const maxAlc = th.alcohol?.max || 800;
                            let alcBadgeClass = 'sensor-badge badge-teal';
                            let alcBadgeText = 'HALAL (AMAN)';
                            if (alcVal > maxAlc) {
                                alcBadgeClass = 'sensor-badge badge-pink';
                                alcBadgeText = 'WASPADA (> ' + maxAlc + ')';
                            } else if (alcVal >= 400) {
                                alcBadgeClass = 'sensor-badge badge-amber';
                                alcBadgeText = 'TRANSISI';
                            }
                            el.badgeAlcohol.className = alcBadgeClass;
                            el.badgeAlcohol.textContent = alcBadgeText;
                            el.footerAlcohol.textContent = 'Batas Maksimal: ≤ ' + maxAlc + ' ADC';
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
        const alcoholData = [];

        let prevEpoch = null;
        const offlineGapThreshold = state.offlineTimeout || 300;

        records.forEach(row => {
            const currentEpoch = row.epoch || (row.datetime ? Math.floor(new Date(row.datetime).getTime() / 1000) : null);

            // DETEKSI GAP / PERIODE OFFLINE:
            // Jika jeda waktu antar 2 titik data melebihi ambang batas offline (misal loncat 9 jam),
            // sisipkan titik 'null' agar Chart.js MEMUTUS garis (blank gap) dan tidak menarik garis miring palsu!
            if (prevEpoch !== null && currentEpoch !== null) {
                const gapSeconds = currentEpoch - prevEpoch;
                if (gapSeconds > offlineGapThreshold) {
                    const gapHrs = Math.floor(gapSeconds / 3600);
                    const gapMins = Math.floor((gapSeconds % 3600) / 60);
                    const gapLabel = gapHrs > 0 ? `Offline ${gapHrs}j ${gapMins}m` : `Offline ${gapMins}m`;

                    labels.push(gapLabel);
                    tempData.push(null);
                    phData.push(null);
                    alcoholData.push(null);
                }
            }

            labels.push(row.time);
            tempData.push(row.temperature);
            phData.push(row.ph);
            alcoholData.push(row.alcohol);

            prevEpoch = currentEpoch;
        });

        state.chartInstance.data.labels = labels;
        state.chartInstance.data.datasets[0].data = tempData;
        state.chartInstance.data.datasets[1].data = phData;
        if (state.chartInstance.data.datasets[2]) {
            state.chartInstance.data.datasets[2].data = alcoholData;
        }
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
        let tableHtml = '';
        let prevEpoch = null;
        const offlineThreshold = state.offlineTimeout || 300;
        let countValid = 0;
        let countOffline = 0;

        recentRows.forEach(row => {
            const currentEpoch = row.epoch || (row.datetime ? Math.floor(new Date(row.datetime).getTime() / 1000) : null);

            // Deteksi downtime antar data berturut-turut di tabel
            if (prevEpoch !== null && currentEpoch !== null) {
                const gapSeconds = prevEpoch - currentEpoch; // urutan menurun (newest first)
                if (gapSeconds > offlineThreshold) {
                    countOffline++;
                    const gapHrs = Math.floor(gapSeconds / 3600);
                    const gapMins = Math.floor((gapSeconds % 3600) / 60);
                    const gapLabel = gapHrs > 0 ? `${gapHrs} jam ${gapMins} menit` : `${gapMins} menit`;
                    const isHidden = (state.tableFilter === 'valid') ? 'style="display:none;"' : '';
                    tableHtml += `
                        <tr class="downtime-row" ${isHidden}>
                            <td colspan="6">
                                <div class="downtime-badge">
                                    <span class="downtime-dot"></span>
                                    <span><strong>PERANGKAT OFFLINE</strong> selama <strong>${gapLabel}</strong></span>
                                </div>
                            </td>
                        </tr>
                    `;
                }
            }
            prevEpoch = currentEpoch;

            countValid++;
            const isHidden = (state.tableFilter === 'offline') ? 'style="display:none;"' : '';
            tableHtml += `
                <tr class="data-row" ${isHidden}>
                    <td>
                        <strong>${escapeHtml(row.time)}</strong>
                        <span style="font-size:0.68rem; color:var(--teal); margin-left:5px; background:var(--teal-soft); padding:1px 6px; border-radius:4px;">${escapeHtml(row.relative_time || 'Baru saja')}</span>
                    </td>
                    <td><span style="color:var(--pink); font-weight:700;">${row.temperature !== null ? row.temperature + ' °C' : '--'}</span></td>
                    <td><span style="color:var(--teal); font-weight:700;">${row.ph !== null ? row.ph : '--'}</span></td>
                    <td><span style="color:var(--amber); font-weight:700;">${row.alcohol !== null ? Math.round(row.alcohol) + ' ADC' : '--'}</span></td>
                    <td>${row.rssi !== null ? row.rssi + ' dBm' : '--'}</td>
                    <td><span class="sensor-badge badge-teal" style="font-size:0.65rem;">VALID</span></td>
                </tr>
            `;
        });

        // Pesan jika filter offline aktif namun tidak ada baris offline
        if (state.tableFilter === 'offline' && countOffline === 0) {
            tableHtml += `
                <tr class="empty-filter-row">
                    <td colspan="6" style="text-align:center; color:var(--teal); padding:18px; font-size:0.8rem;">
                        <span class="filter-dot online" style="display:inline-block; vertical-align:middle; margin-right:6px;"></span>
                        Tidak ada periode offline — transmisi sensor berlangsung stabil tanpa jeda downtime
                    </td>
                </tr>
            `;
        }

        el.historyTableBody.innerHTML = tableHtml;
        updateTableFilterBadges(countValid, countOffline);
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

    // --------------------------------------------------------------------------
    // 7b. FILTER STATUS DEVICE (Semua / Online / Offline)
    // --------------------------------------------------------------------------

    /**
     * Ambil daftar device dari API, simpan status ke state.deviceMap,
     * lalu terapkan filter ke opsi dropdown.
     */
    async function fetchAndApplyDeviceFilter() {
        try {
            const res = await fetch('api/devices.php');
            const json = await res.json();
            if (json.status !== 'ok' || !Array.isArray(json.devices)) return;

            // Sinkronkan offline timeout jika ada
            if (json.offline_timeout_seconds) {
                state.offlineTimeout = json.offline_timeout_seconds;
            }

            // Simpan status setiap device ke map
            json.devices.forEach(dev => {
                state.deviceMap[dev.device_id] = {
                    is_online: dev.is_online,
                    device_name: dev.device_name,
                    seconds_ago: dev.seconds_ago,
                    relative_time: dev.relative_time,
                };
            });

            applyDeviceFilter();
        } catch (err) {
            console.warn('Gagal memuat daftar device:', err);
        }
    }

    /**
     * Terapkan filter visual ke opsi di dalam <select#deviceSelect>
     * berdasarkan state.deviceStatusFilter ('all'|'online'|'offline')
     */
    function applyDeviceFilter() {
        if (!el.deviceSelect) return;
        const filterVal = state.deviceStatusFilter;
        const options = el.deviceSelect.querySelectorAll('option');

        let firstVisibleValue = null;
        options.forEach(opt => {
            const devId = opt.value;
            const devStatus = state.deviceMap[devId];

            // Jika belum ada di map (baru dimuat), tampilkan saja
            if (!devStatus) {
                opt.hidden = false;
                if (!firstVisibleValue) firstVisibleValue = devId;
                return;
            }

            let visible = true;
            if (filterVal === 'online')  visible = devStatus.is_online;
            if (filterVal === 'offline') visible = !devStatus.is_online;

            opt.hidden = !visible;
            if (visible && !firstVisibleValue) firstVisibleValue = devId;
        });

        // Jika device yang sedang aktif tersembunyi, otomatis pindah ke device pertama yang terlihat
        const currentOpt = el.deviceSelect.querySelector(`option[value="${state.currentDeviceId}"]`);
        if (currentOpt && currentOpt.hidden && firstVisibleValue) {
            el.deviceSelect.value = firstVisibleValue;
            state.currentDeviceId = firstVisibleValue;
            fetchLatestData();
            fetchHistoryData();
        } else if (!currentOpt && firstVisibleValue) {
            el.deviceSelect.value = firstVisibleValue;
            state.currentDeviceId = firstVisibleValue;
            fetchLatestData();
            fetchHistoryData();
        }

        // Update label filter button device agar tampilkan jumlah
        const total   = Object.keys(state.deviceMap).length;
        const online  = Object.values(state.deviceMap).filter(d => d.is_online).length;
        const offline = total - online;

        if (el.filterDeviceBtns) {
            el.filterDeviceBtns.forEach(btn => {
                const s = btn.getAttribute('data-status');
                if (s === 'all')     btn.textContent = total   > 0 ? `Semua (${total})`   : 'Semua';
                if (s === 'online')  btn.innerHTML   = online  > 0
                    ? `<span class="filter-dot online"></span>Online (${online})`
                    : `<span class="filter-dot online"></span>Online`;
                if (s === 'offline') btn.innerHTML   = offline > 0
                    ? `<span class="filter-dot offline"></span>Offline (${offline})`
                    : `<span class="filter-dot offline"></span>Offline`;
            });
        }
    }

    // Event listener tombol filter status perangkat di header
    if (el.filterDeviceBtns) {
        el.filterDeviceBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                el.filterDeviceBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                state.deviceStatusFilter = btn.getAttribute('data-status') || 'all';
                applyDeviceFilter();
                const label = { all: 'Semua', online: 'Online Saja', offline: 'Offline Saja' };
                showToast(`Filter perangkat: ${label[state.deviceStatusFilter] || ''}`);
            });
        });
    }

    // --------------------------------------------------------------------------
    // 7c. FILTER BARIS TABEL TELEMETRI (Semua / Valid (Ada Data) / Offline)
    // --------------------------------------------------------------------------

    /**
     * Perbarui angka counter badge pada filter baris tabel realtime
     */
    function updateTableFilterBadges(validCount, offlineCount) {
        if (!el.filterTableBtns) return;
        const total = validCount + offlineCount;
        el.filterTableBtns.forEach(btn => {
            const f = btn.getAttribute('data-tablefilter');
            if (f === 'all')     btn.textContent = total > 0 ? `Semua (${total})` : 'Semua';
            if (f === 'valid')   btn.innerHTML   = validCount > 0
                ? `<span class="filter-dot online"></span>Valid (Ada Data) (${validCount})`
                : `<span class="filter-dot online"></span>Valid (Ada Data)`;
            if (f === 'offline') btn.innerHTML   = offlineCount > 0
                ? `<span class="filter-dot offline"></span>Offline (${offlineCount})`
                : `<span class="filter-dot offline"></span>Offline (0)`;
        });
    }

    /**
     * Terapkan filter tampilan baris tabel telemetri secara instan
     */
    function applyTableFilter(filterVal) {
        state.tableFilter = filterVal;
        if (!el.historyTableBody) return;
        const dataRows = el.historyTableBody.querySelectorAll('.data-row');
        const offlineRows = el.historyTableBody.querySelectorAll('.downtime-row');
        const emptyRows = el.historyTableBody.querySelectorAll('.empty-filter-row');
        emptyRows.forEach(r => r.remove());

        dataRows.forEach(r => {
            r.style.display = (filterVal === 'offline') ? 'none' : '';
        });
        offlineRows.forEach(r => {
            r.style.display = (filterVal === 'valid') ? 'none' : '';
        });

        if (filterVal === 'offline' && offlineRows.length === 0) {
            el.historyTableBody.insertAdjacentHTML('beforeend', `
                <tr class="empty-filter-row">
                    <td colspan="6" style="text-align:center; color:var(--teal); padding:18px; font-size:0.8rem;">
                        <span class="filter-dot online" style="display:inline-block; vertical-align:middle; margin-right:6px;"></span>
                        Tidak ada periode offline — transmisi sensor berlangsung stabil tanpa jeda downtime
                    </td>
                </tr>
            `);
        }
    }

    // Event listener tombol filter baris tabel realtime (Semua / Valid (Ada Data) / Offline)
    if (el.filterTableBtns) {
        el.filterTableBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                el.filterTableBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const f = btn.getAttribute('data-tablefilter') || 'all';
                applyTableFilter(f);
                const label = { all: 'Semua Data & Offline', valid: 'Hanya Data Valid', offline: 'Hanya Periode Offline' };
                showToast(`Filter tabel: ${label[f] || ''}`);
            });
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
    fetchAndApplyDeviceFilter();   // Inisialisasi filter status device

    // Polling data realtime dari server tiap 3 detik
    state.pollTimer = setInterval(fetchLatestData, state.pollIntervalMs);

    // Refresh grafik & tabel otomatis tiap 6 detik (fallback jika tidak ada push baru)
    setInterval(fetchHistoryData, 6000);

    // Refresh status filter device setiap 30 detik (update badge jumlah online/offline)
    setInterval(fetchAndApplyDeviceFilter, 30000);

    // Ticker hitungan detik update tiap 1 detik
    state.secondsTickerTimer = setInterval(updateRelativeTimeCounter, 1000);

    // Auto-sync instan saat user membuka kembali tab browser (misal setelah switch tab)
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            fetchLatestData();
            fetchHistoryData();
            fetchAndApplyDeviceFilter();
        }
    });
});
