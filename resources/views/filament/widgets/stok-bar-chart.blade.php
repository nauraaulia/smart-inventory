<x-filament-widgets::widget>
    <x-filament::section heading="Inventory Management Dashboard">
        <div
            x-data="{
                chartBaseId: '',
                barChartId: '',
                topMaterialsChartId: '',
                donutChartId: '',
                lineChartId: '',
                agingChartId: '',
                riskChartId: '',
                outboundChartId: '',
                projectPipelineChartId: '',
                projectMonthlyChartId: '',
                kpiCards: [],
                categories: [],
                satuanPerMaterial: [],
                availableSeries: [],
                bookedSeries: [],
                usedSeries: [],
                compositionSeries: [],
                inboundMonths: [],
                inboundSeries: [],
                inboundQtySeries: [],
                topMaterialsCategories: [],
                topMaterialsSeries: [],
                topMaterialsSatuan: [],
                agingLabels: [],
                agingSeries: [],
                riskSeries: [],
                outboundMonths: [],
                outboundPendingSeries: [],
                outboundReadySeries: [],
                outboundTakenSeries: [],
                projectPending: 0,
                projectCompleted: 0,
                projectMonthlySeries: [],
                barMode: 'grouped',
                monthWindow: 12,
                selectedMonthIndex: null,
                selectedMonthLabel: '-',
                selectedInboundCount: '0',
                selectedInboundQty: '0',
                maxMaterialBars: 20,
                bootError: '',
            }"
            x-init="
                const config = {
                    chartBaseId: @js($chartBaseId),
                    kpiCards: @js($kpiCards),
                    categories: @js($categories),
                    satuanPerMaterial: @js($satuanPerMaterial),
                    availableSeries: @js($availableSeries),
                    bookedSeries: @js($bookedSeries),
                    usedSeries: @js($usedSeries),
                    compositionSeries: @js($compositionSeries),
                    inboundMonths: @js($inboundMonths),
                    inboundSeries: @js($inboundSeries),
                    inboundQtySeries: @js($inboundQtySeries),
                    topMaterialsCategories: @js($topMaterialsCategories),
                    topMaterialsSeries: @js($topMaterialsSeries),
                    topMaterialsSatuan: @js($topMaterialsSatuan),
                    agingLabels: @js($agingLabels),
                    agingSeries: @js($agingSeries),
                    riskSeries: @js($riskSeries),
                    outboundMonths: @js($outboundMonths),
                    outboundPendingSeries: @js($outboundPendingSeries),
                    outboundReadySeries: @js($outboundReadySeries),
                    outboundTakenSeries: @js($outboundTakenSeries),
                    projectPending: @js($projectPending),
                    projectCompleted: @js($projectCompleted),
                    projectMonthlySeries: @js($projectMonthlySeries),
                };

                let apexScriptRequested = false;
                let attempts = 0;
                const tryBoot = () => {
                    attempts += 1;
                    if (typeof window.ApexCharts === 'undefined' && !apexScriptRequested) {
                        const script = document.createElement('script');
                        script.src = 'https://cdn.jsdelivr.net/npm/apexcharts';
                        script.async = true;
                        document.head.appendChild(script);
                        apexScriptRequested = true;
                    }
                    if (typeof window.stokBarChartWidget !== 'function' || typeof window.ApexCharts === 'undefined') {
                        if (attempts > 80) {
                            $data.bootError = 'Gagal memuat chart. Coba refresh halaman.';
                            return;
                        }
                        setTimeout(tryBoot, 150);
                        return;
                    }
                    Object.assign($data, window.stokBarChartWidget(config));
                    $nextTick(() => $data.renderAll());
                };
                tryBoot();
            "
            class="w-full rounded-2xl border border-gray-200 bg-white p-4 shadow dark:border-gray-700 dark:bg-gray-900"
        >
            {{-- FILTER BAR --}}
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-4 dark:border-gray-700">
                <div class="flex items-center gap-2">
                    <span class="text-base font-semibold text-gray-800 dark:text-white">Inventory Dashboard</span>
                    <span class="rounded-full bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">Live</span>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-gray-500 dark:text-gray-400">Per Bulan:</span>
                    <div class="flex max-w-[48vw] items-center gap-2 overflow-x-auto">
                        <template x-for="(month, idx) in inboundMonths.slice(-6)" :key="idx">
                            <button
                                type="button"
                                class="rounded-md border px-3 py-1 font-medium transition"
                                :class="selectedMonthIndex === (inboundMonths.length - 6 + idx)
                                    ? 'border-primary-500 bg-primary-500 text-white'
                                    : 'border-gray-200 bg-white text-gray-600 hover:border-primary-300 hover:text-primary-600 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300'"
                                @click="selectMonth(inboundMonths.length - 6 + idx)"
                                x-text="month"
                            ></button>
                        </template>
                    </div>

                    <span class="ml-2 text-gray-500 dark:text-gray-400">Range:</span>
                    <template x-for="opt in [3, 6, 12]" :key="opt">
                        <button
                            type="button"
                            class="rounded-md border px-3 py-1 font-medium transition"
                            :disabled="selectedMonthIndex !== null"
                            :class="selectedMonthIndex !== null
                                ? 'cursor-not-allowed border-gray-200 bg-gray-100 text-gray-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-500'
                                : (monthWindow === opt
                                    ? 'border-primary-500 bg-primary-500 text-white'
                                    : 'border-gray-200 bg-white text-gray-600 hover:border-primary-300 hover:text-primary-600 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300')"
                            @click="setMonthWindow(opt)"
                            x-text="opt + 'M'"
                        ></button>
                    </template>
                </div>
            </div>

            {{-- KPI CARDS --}}
            <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <template x-for="(card, idx) in kpiCards" :key="idx">
                    <div class="rounded-xl border border-gray-100 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800">
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400" x-text="card.label"></div>
                        <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-white" x-text="card.value"></div>
                        <div class="mt-0.5 text-xs text-gray-400 dark:text-gray-500" x-text="card.suffix"></div>
                    </div>
                </template>
            </div>

            {{-- SELECTED MONTH INFO --}}
            <div class="mb-5 rounded-xl border border-primary-100 bg-primary-50 px-4 py-3 dark:border-primary-900/30 dark:bg-primary-900/10">
                <div class="flex flex-wrap items-center gap-6 text-sm">
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Periode dipilih: </span>
                        <span class="font-semibold text-primary-700 dark:text-primary-300" x-text="selectedMonthLabel"></span>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Inbound Count: </span>
                        <span class="font-semibold text-gray-800 dark:text-gray-200" x-text="selectedInboundCount"></span>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Inbound Qty: </span>
                        <span class="font-semibold text-gray-800 dark:text-gray-200" x-text="selectedInboundQty"></span>
                    </div>
                </div>
            </div>

            {{-- ROW 1: Bar Chart + Top Materials --}}
            <div class="mb-4 grid grid-cols-1 gap-4 xl:grid-cols-12">
                <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800 xl:col-span-8">
                    <div class="mb-3 flex items-center justify-between">
                        <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Available vs Booked vs Used per Material</div>
                        <div class="flex gap-1 text-xs">
                            <button type="button" class="rounded border px-2 py-1 transition"
                                :class="barMode === 'grouped' ? 'border-primary-500 bg-primary-500 text-white' : 'border-gray-200 text-gray-600 dark:border-gray-600 dark:text-gray-300'"
                                @click="setBarMode('grouped')">Grouped</button>
                            <button type="button" class="rounded border px-2 py-1 transition"
                                :class="barMode === 'stacked' ? 'border-primary-500 bg-primary-500 text-white' : 'border-gray-200 text-gray-600 dark:border-gray-600 dark:text-gray-300'"
                                @click="setBarMode('stacked')">Stacked</button>
                        </div>
                    </div>
                    <div x-show="bootError" x-text="bootError" class="mb-2 text-xs text-danger-600 dark:text-danger-400"></div>
                    <div :id="barChartId" style="height:320px;"></div>
                </div>
                <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800 xl:col-span-4">
                    <div class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Top Material by Stock On Hand</div>
                    <div :id="topMaterialsChartId" style="height:320px;"></div>
                </div>
            </div>

            {{-- ROW 2: Donut + Inbound Trend + Risk --}}
            <div class="mb-4 grid grid-cols-1 gap-4 xl:grid-cols-3">
                <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Stock Composition</div>
                    <div :id="donutChartId" style="height:280px;"></div>
                </div>
                <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Inbound Trend per Bulan</div>
                    </div>
                    <div :id="lineChartId" style="height:280px;"></div>
                </div>
                <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Aging Risk % (&gt;90 hari)</div>
                    <div :id="riskChartId" style="height:280px;"></div>
                </div>
            </div>

            {{-- ROW 3: Outbound Trend + Project Pipeline + Project Monthly --}}
            <div class="mb-4 grid grid-cols-1 gap-4 xl:grid-cols-3">
                <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Outbound Trend per Bulan</div>
                    </div>
                    <div :id="outboundChartId" style="height:280px;"></div>
                </div>
                <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Project Pipeline</div>
                    <div :id="projectPipelineChartId" style="height:280px;"></div>
                </div>
                <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Project Masuk per Bulan</div>
                    <div :id="projectMonthlyChartId" style="height:280px;"></div>
                </div>
            </div>

            {{-- ROW 4: Aging Bucket --}}
            <div class="mb-4 rounded-xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Aging Bucket Available Pieces</div>
                <div :id="agingChartId" style="height:240px;"></div>
            </div>

        </div>
    </x-filament::section>

    @once
        @script
        <script>
            window.stokBarChartWidget = function (config) {
                const isDark = () => document.documentElement.classList.contains('dark');
                const textColor = () => isDark() ? '#9ca3af' : '#6b7280';
                const gridColor = () => isDark() ? '#374151' : '#f1f5f9';
                const bgColor = () => 'transparent';

                return {
                    chartBaseId: config.chartBaseId,
                    barChartId: `${config.chartBaseId}-bar`,
                    topMaterialsChartId: `${config.chartBaseId}-top`,
                    donutChartId: `${config.chartBaseId}-donut`,
                    lineChartId: `${config.chartBaseId}-line`,
                    agingChartId: `${config.chartBaseId}-aging`,
                    riskChartId: `${config.chartBaseId}-risk`,
                    outboundChartId: `${config.chartBaseId}-outbound`,
                    projectPipelineChartId: `${config.chartBaseId}-pipeline`,
                    projectMonthlyChartId: `${config.chartBaseId}-project-monthly`,
                    kpiCards: config.kpiCards,
                    categories: config.categories,
                    satuanPerMaterial: config.satuanPerMaterial || [],
                    availableSeries: config.availableSeries,
                    bookedSeries: config.bookedSeries,
                    usedSeries: config.usedSeries,
                    compositionSeries: config.compositionSeries,
                    inboundMonths: config.inboundMonths,
                    inboundSeries: config.inboundSeries,
                    inboundQtySeries: config.inboundQtySeries,
                    topMaterialsCategories: config.topMaterialsCategories,
                    topMaterialsSeries: config.topMaterialsSeries,
                    topMaterialsSatuan: config.topMaterialsSatuan || [],
                    agingLabels: config.agingLabels,
                    agingSeries: config.agingSeries,
                    riskSeries: config.riskSeries,
                    outboundMonths: config.outboundMonths,
                    outboundPendingSeries: config.outboundPendingSeries,
                    outboundReadySeries: config.outboundReadySeries,
                    outboundTakenSeries: config.outboundTakenSeries,
                    projectPending: config.projectPending,
                    projectCompleted: config.projectCompleted,
                    projectMonthlySeries: config.projectMonthlySeries,
                    barMode: 'grouped',
                    monthWindow: 12,
                    selectedMonthLabel: '-',
                    selectedInboundCount: '0',
                    selectedInboundQty: '0',
                    barChart: null,
                    topMaterialsChart: null,
                    donutChart: null,
                    lineChart: null,
                    agingChart: null,
                    riskChart: null,
                    outboundChart: null,
                    projectPipelineChart: null,
                    projectMonthlyChart: null,
                    themeObserver: null,

                    renderAll() {
                        if (typeof window.ApexCharts === 'undefined') {
                            setTimeout(() => this.renderAll(), 300);
                            return;
                        }
                        this.setupThemeWatcher();
                        this.refreshSelectedPeriod();
                        this.renderBarChart();
                        this.renderTopMaterialsChart();
                        this.renderDonutChart();
                        this.renderLineChart();
                        this.renderAgingChart();
                        this.renderRiskChart();
                        this.renderOutboundChart();
                        this.renderProjectPipelineChart();
                        this.renderProjectMonthlyChart();
                    },

                    selectMonth(idx) {
                        this.selectedMonthIndex = this.selectedMonthIndex === idx ? null : idx;
                        this.refreshSelectedPeriod();
                        this.renderLineChart();
                        this.renderRiskChart();
                        this.renderOutboundChart();
                        this.renderProjectMonthlyChart();
                    },

                    setMonthWindow(months) {
                        if (this.selectedMonthIndex !== null) {
                            return;
                        }
                        this.monthWindow = months;
                        this.refreshSelectedPeriod();
                        this.renderLineChart();
                        this.renderRiskChart();
                        this.renderOutboundChart();
                        this.renderProjectMonthlyChart();
                    },

                    getActiveRange() {
                        if (this.selectedMonthIndex !== null) {
                            return { start: this.selectedMonthIndex, end: this.selectedMonthIndex };
                        }

                        const start = Math.max(0, this.inboundMonths.length - this.monthWindow);
                        const end = Math.max(0, this.inboundMonths.length - 1);

                        return { start, end };
                    },

                    setupThemeWatcher() {
                        if (this.themeObserver) return;
                        this.themeObserver = new MutationObserver(() => this.renderAll());
                        this.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
                    },

                    refreshSelectedPeriod() {
                        const { start, end } = this.getActiveRange();
                        const visibleMonths = this.inboundMonths.slice(start, end + 1);
                        const firstLabel = visibleMonths[0] || '-';
                        const lastLabel = visibleMonths[visibleMonths.length - 1] || '-';

                        this.selectedMonthLabel = firstLabel === lastLabel ? firstLabel : `${firstLabel} - ${lastLabel}`;
                        this.selectedInboundCount = Number(
                            this.inboundSeries.slice(start, end + 1).reduce((sum, val) => sum + Number(val || 0), 0)
                        ).toLocaleString();
                        this.selectedInboundQty = Number(
                            this.inboundQtySeries.slice(start, end + 1).reduce((sum, val) => sum + Number(val || 0), 0)
                        ).toLocaleString(undefined, { maximumFractionDigits: 2 });
                    },

                    setBarMode(mode) {
                        this.barMode = mode;
                        if (this.barChart) {
                            this.barChart.updateOptions({
                                chart: { stacked: mode === 'stacked' },
                                plotOptions: { bar: { columnWidth: mode === 'stacked' ? '65%' : '55%' } },
                            });
                        }
                    },

                    renderBarChart() {
                        const el = document.getElementById(this.barChartId);
                        if (!el) return;
                        if (this.barChart) this.barChart.destroy();

                        const indices = this.categories
                            .map((_, idx) => ({
                                idx,
                                total: Number(this.availableSeries[idx] || 0)
                                    + Number(this.bookedSeries[idx] || 0)
                                    + Number(this.usedSeries[idx] || 0),
                            }))
                            .sort((a, b) => b.total - a.total)
                            .slice(0, this.maxMaterialBars)
                            .map((row) => row.idx);

                        const categories = indices.map((idx) => this.categories[idx]);
                        const availableSeries = indices.map((idx) => this.availableSeries[idx]);
                        const bookedSeries = indices.map((idx) => this.bookedSeries[idx]);
                        const usedSeries = indices.map((idx) => this.usedSeries[idx]);
                        const satuan = indices.map((idx) => this.satuanPerMaterial[idx] || 'unit');

                        this.barChart = new window.ApexCharts(el, {
                            chart: { type: 'bar', height: 320, stacked: this.barMode === 'stacked', toolbar: { show: true }, fontFamily: 'inherit', background: bgColor() },
                            theme: { mode: isDark() ? 'dark' : 'light' },
                            series: [
                                { name: 'Available', data: availableSeries },
                                { name: 'Booked', data: bookedSeries },
                                { name: 'Used', data: usedSeries },
                            ],
                            xaxis: { categories, labels: { rotate: -35, trim: true, style: { fontSize: '11px', colors: textColor() } } },
                            yaxis: { title: { text: 'Total', style: { color: textColor() } }, labels: { style: { colors: textColor() } } },
                            colors: ['#10b981', '#f59e0b', '#6366f1'],
                            dataLabels: { enabled: false },
                            plotOptions: { bar: { columnWidth: '55%', borderRadius: 3 } },
                            legend: { position: 'top', labels: { colors: textColor() } },
                            tooltip: { y: { formatter: (val, { dataPointIndex }) => `${val} ${satuan[dataPointIndex] || 'unit'}` } },
                            grid: { borderColor: gridColor() },
                        });
                        this.barChart.render();
                    },

                    renderTopMaterialsChart() {
                        const el = document.getElementById(this.topMaterialsChartId);
                        if (!el) return;
                        if (this.topMaterialsChart) this.topMaterialsChart.destroy();
                        const satuan = this.topMaterialsSatuan;
                        this.topMaterialsChart = new window.ApexCharts(el, {
                            chart: { type: 'bar', height: 320, toolbar: { show: false }, fontFamily: 'inherit', background: bgColor() },
                            theme: { mode: isDark() ? 'dark' : 'light' },
                            series: [{ name: 'Available', data: this.topMaterialsSeries }],
                            xaxis: { categories: this.topMaterialsCategories, labels: { show: false } },
                            yaxis: { labels: { style: { colors: textColor() } } },
                            plotOptions: { bar: { horizontal: true, borderRadius: 3 } },
                            colors: ['#6366f1'],
                            dataLabels: { enabled: true, formatter: (val, { dataPointIndex }) => `${val} ${satuan[dataPointIndex] || 'unit'}`, style: { fontSize: '11px' } },
                            grid: { borderColor: gridColor() },
                            tooltip: { y: { formatter: (val, { dataPointIndex }) => `${val} ${satuan[dataPointIndex] || 'unit'}` } },
                        });
                        this.topMaterialsChart.render();
                    },

                    renderDonutChart() {
                        const el = document.getElementById(this.donutChartId);
                        if (!el) return;
                        if (this.donutChart) this.donutChart.destroy();
                        this.donutChart = new window.ApexCharts(el, {
                            chart: { type: 'donut', height: 280, fontFamily: 'inherit', background: bgColor() },
                            theme: { mode: isDark() ? 'dark' : 'light' },
                            labels: ['Available', 'Booked', 'Used'],
                            series: this.compositionSeries,
                            colors: ['#10b981', '#f59e0b', '#6366f1'],
                            legend: { position: 'bottom', labels: { colors: textColor() } },
                            dataLabels: { enabled: true, formatter: v => `${v.toFixed(1)}%` },
                            plotOptions: { pie: { donut: { size: '60%', labels: { show: true, total: { show: true, label: 'Total', color: textColor(), formatter: w => w.globals.seriesTotals.reduce((a, b) => a + b, 0).toFixed(2) } } } } },
                        });
                        this.donutChart.render();
                    },

                    renderLineChart() {
                        const el = document.getElementById(this.lineChartId);
                        if (!el) return;
                        if (this.lineChart) this.lineChart.destroy();
                        const { start, end } = this.getActiveRange();
                        this.lineChart = new window.ApexCharts(el, {
                            chart: { type: 'line', height: 280, toolbar: { show: false }, fontFamily: 'inherit', zoom: { enabled: false }, background: bgColor() },
                            theme: { mode: isDark() ? 'dark' : 'light' },
                            series: [
                                { name: 'Inbound Count', data: this.inboundSeries.slice(start, end + 1) },
                                { name: 'Inbound Qty', data: this.inboundQtySeries.slice(start, end + 1) },
                            ],
                            xaxis: { categories: this.inboundMonths.slice(start, end + 1), labels: { style: { fontSize: '11px', colors: textColor() } } },
                            yaxis: { min: 0, forceNiceScale: true, labels: { style: { colors: textColor() } } },
                            stroke: { width: 2.5, curve: 'smooth' },
                            markers: { size: 4 },
                            colors: ['#6366f1', '#14b8a6'],
                            fill: { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.05 } },
                            tooltip: { y: { formatter: (v, o) => o.seriesIndex === 0 ? `${v} inbound` : `${v} item` } },
                            grid: { borderColor: gridColor() },
                        });
                        this.lineChart.render();
                    },

                    renderAgingChart() {
                        const el = document.getElementById(this.agingChartId);
                        if (!el) return;
                        if (this.agingChart) this.agingChart.destroy();
                        this.agingChart = new window.ApexCharts(el, {
                            chart: { type: 'bar', height: 240, toolbar: { show: false }, fontFamily: 'inherit', background: bgColor() },
                            theme: { mode: isDark() ? 'dark' : 'light' },
                            series: [{ name: 'Pieces', data: this.agingSeries }],
                            xaxis: { categories: this.agingLabels, labels: { style: { colors: textColor() } } },
                            colors: ['#f59e0b'],
                            dataLabels: { enabled: true },
                            plotOptions: { bar: { columnWidth: '45%', borderRadius: 3 } },
                            grid: { borderColor: gridColor() },
                        });
                        this.agingChart.render();
                    },

                    renderRiskChart() {
                        const el = document.getElementById(this.riskChartId);
                        if (!el) return;
                        if (this.riskChart) this.riskChart.destroy();
                        const { start, end } = this.getActiveRange();
                        this.riskChart = new window.ApexCharts(el, {
                            chart: { type: 'area', height: 280, toolbar: { show: false }, fontFamily: 'inherit', background: bgColor() },
                            theme: { mode: isDark() ? 'dark' : 'light' },
                            series: [{ name: 'Aging Risk', data: this.riskSeries.slice(start, end + 1) }],
                            xaxis: { categories: this.inboundMonths.slice(start, end + 1), labels: { style: { fontSize: '11px', colors: textColor() } } },
                            yaxis: { min: 0, max: 100, labels: { formatter: v => `${v}%`, style: { colors: textColor() } } },
                            stroke: { curve: 'smooth', width: 2.5 },
                            colors: ['#ef4444'],
                            fill: { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.05 } },
                            tooltip: { y: { formatter: v => `${v}%` } },
                            grid: { borderColor: gridColor() },
                        });
                        this.riskChart.render();
                    },

                    renderOutboundChart() {
                        const el = document.getElementById(this.outboundChartId);
                        if (!el) return;
                        if (this.outboundChart) this.outboundChart.destroy();
                        const { start, end } = this.getActiveRange();
                        this.outboundChart = new window.ApexCharts(el, {
                            chart: { type: 'bar', height: 280, stacked: true, toolbar: { show: false }, fontFamily: 'inherit', background: bgColor() },
                            theme: { mode: isDark() ? 'dark' : 'light' },
                            series: [
                                { name: 'Pending', data: this.outboundPendingSeries.slice(start, end + 1) },
                                { name: 'Ready', data: this.outboundReadySeries.slice(start, end + 1) },
                                { name: 'Taken', data: this.outboundTakenSeries.slice(start, end + 1) },
                            ],
                            xaxis: { categories: this.outboundMonths.slice(start, end + 1), labels: { style: { fontSize: '11px', colors: textColor() } } },
                            yaxis: { labels: { style: { colors: textColor() } } },
                            colors: ['#f59e0b', '#6366f1', '#10b981'],
                            dataLabels: { enabled: false },
                            plotOptions: { bar: { columnWidth: '55%', borderRadius: 2 } },
                            legend: { position: 'top', labels: { colors: textColor() } },
                            grid: { borderColor: gridColor() },
                        });
                        this.outboundChart.render();
                    },

                    renderProjectPipelineChart() {
                        const el = document.getElementById(this.projectPipelineChartId);
                        if (!el) return;
                        if (this.projectPipelineChart) this.projectPipelineChart.destroy();
                        this.projectPipelineChart = new window.ApexCharts(el, {
                            chart: { type: 'donut', height: 280, fontFamily: 'inherit', background: bgColor() },
                            theme: { mode: isDark() ? 'dark' : 'light' },
                            labels: ['Pending', 'Completed'],
                            series: [this.projectPending, this.projectCompleted],
                            colors: ['#f59e0b', '#10b981'],
                            legend: { position: 'bottom', labels: { colors: textColor() } },
                            dataLabels: { enabled: true, formatter: v => `${v.toFixed(1)}%` },
                            plotOptions: { pie: { donut: { size: '60%', labels: { show: true, total: { show: true, label: 'Total Project', color: textColor(), formatter: w => w.globals.seriesTotals.reduce((a, b) => a + b, 0) } } } } },
                        });
                        this.projectPipelineChart.render();
                    },

                    renderProjectMonthlyChart() {
                        const el = document.getElementById(this.projectMonthlyChartId);
                        if (!el) return;
                        if (this.projectMonthlyChart) this.projectMonthlyChart.destroy();
                        const { start, end } = this.getActiveRange();
                        this.projectMonthlyChart = new window.ApexCharts(el, {
                            chart: { type: 'bar', height: 280, toolbar: { show: false }, fontFamily: 'inherit', background: bgColor() },
                            theme: { mode: isDark() ? 'dark' : 'light' },
                            series: [{ name: 'Project Baru', data: this.projectMonthlySeries.slice(start, end + 1) }],
                            xaxis: { categories: this.inboundMonths.slice(start, end + 1), labels: { style: { fontSize: '11px', colors: textColor() } } },
                            yaxis: { labels: { style: { colors: textColor() } } },
                            colors: ['#8b5cf6'],
                            dataLabels: { enabled: true },
                            plotOptions: { bar: { columnWidth: '45%', borderRadius: 3 } },
                            grid: { borderColor: gridColor() },
                        });
                        this.projectMonthlyChart.render();
                    },
                };
            };
        </script>
        @endscript
    @endonce
</x-filament-widgets::widget>