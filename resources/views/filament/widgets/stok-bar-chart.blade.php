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
                kpiCards: [],
                categories: [],
                availableSeries: [],
                bookedSeries: [],
                usedSeries: [],
                compositionSeries: [],
                inboundMonths: [],
                inboundSeries: [],
                inboundQtySeries: [],
                topMaterialsCategories: [],
                topMaterialsSeries: [],
                agingLabels: [],
                agingSeries: [],
                riskSeries: [],
                barMode: 'grouped',
                monthWindow: 12,
                selectedMonthIndex: 0,
                selectedMonthLabel: '-',
                selectedInboundCount: '0',
                selectedInboundQty: '0',
            }"
            x-init="
                const config = {
                    chartBaseId: @js($chartBaseId),
                    kpiCards: @js($kpiCards),
                    categories: @js($categories),
                    availableSeries: @js($availableSeries),
                    bookedSeries: @js($bookedSeries),
                    usedSeries: @js($usedSeries),
                    compositionSeries: @js($compositionSeries),
                    inboundMonths: @js($inboundMonths),
                    inboundSeries: @js($inboundSeries),
                    inboundQtySeries: @js($inboundQtySeries),
                    topMaterialsCategories: @js($topMaterialsCategories),
                    topMaterialsSeries: @js($topMaterialsSeries),
                    agingLabels: @js($agingLabels),
                    agingSeries: @js($agingSeries),
                    riskSeries: @js($riskSeries),
                };

                const boot = () => {
                    if (typeof window.stokBarChartWidget !== 'function') {
                        setTimeout(boot, 120);
                        return;
                    }

                    Object.assign($data, window.stokBarChartWidget(config));
                    $data.renderAll();
                };

                boot();
            "
            class="w-full rounded-2xl border border-gray-200 bg-gradient-to-b from-cyan-800 to-cyan-900 p-3 text-white shadow-xl dark:border-gray-800"
        >
            <div class="grid grid-cols-1 gap-3 xl:grid-cols-12">
                <aside class="rounded-xl border border-cyan-600/60 bg-cyan-900/70 p-3 xl:col-span-2">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wider text-cyan-100">Period</div>
                    <div class="space-y-1">
                        <template x-for="(month, idx) in inboundMonths" :key="idx">
                            <button
                                type="button"
                                class="w-full rounded-md border px-2 py-1 text-left text-xs transition"
                                :class="selectedMonthIndex === idx
                                    ? 'border-amber-300 bg-amber-300/20 text-amber-100'
                                    : 'border-cyan-500/50 bg-cyan-950/40 text-cyan-100 hover:bg-cyan-800/80'"
                                @click="selectMonth(idx)"
                                x-text="month"
                            ></button>
                        </template>
                    </div>

                    <div class="mt-3 rounded-md border border-cyan-500/50 bg-cyan-950/40 p-2">
                        <div class="text-[11px] uppercase tracking-wider text-cyan-200">Selected</div>
                        <div class="mt-1 text-sm font-semibold" x-text="selectedMonthLabel"></div>
                        <div class="mt-2 text-xs text-cyan-100">Inbound Count: <span class="font-semibold" x-text="selectedInboundCount"></span></div>
                        <div class="text-xs text-cyan-100">Inbound Qty: <span class="font-semibold" x-text="selectedInboundQty"></span></div>
                    </div>
                </aside>

                <main class="space-y-3 xl:col-span-10">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <template x-for="(card, idx) in kpiCards" :key="idx">
                            <div class="rounded-xl border border-cyan-600/50 bg-white/95 p-3 text-gray-900 shadow">
                                <div class="text-[11px] uppercase tracking-wide text-gray-500" x-text="card.label"></div>
                                <div class="mt-1 text-3xl font-semibold leading-none">
                                    <span x-text="card.value"></span>
                                </div>
                                <div class="mt-1 text-xs text-gray-500" x-text="card.suffix"></div>
                            </div>
                        </template>
                    </div>

                    <div class="grid grid-cols-1 gap-3 xl:grid-cols-12">
                        <div class="rounded-xl border border-cyan-600/50 bg-white/95 p-3 shadow xl:col-span-8">
                            <div class="mb-2 flex items-center justify-between">
                                <div class="text-sm font-semibold text-gray-800">Available vs Booked vs Used per Material</div>
                                <div class="flex items-center gap-2 text-xs">
                                    <button
                                        type="button"
                                        class="rounded-md border px-2 py-1"
                                        :class="barMode === 'grouped' ? 'border-cyan-600 bg-cyan-600 text-white' : 'border-gray-300 text-gray-700'"
                                        @click="setBarMode('grouped')"
                                    >Grouped</button>
                                    <button
                                        type="button"
                                        class="rounded-md border px-2 py-1"
                                        :class="barMode === 'stacked' ? 'border-cyan-600 bg-cyan-600 text-white' : 'border-gray-300 text-gray-700'"
                                        @click="setBarMode('stacked')"
                                    >Stacked</button>
                                </div>
                            </div>
                            <div :id="barChartId" class="h-80"></div>
                        </div>

                        <div class="rounded-xl border border-cyan-600/50 bg-white/95 p-3 shadow xl:col-span-4">
                            <div class="text-sm font-semibold text-gray-800">Top Stock On Hand by Material</div>
                            <div :id="topMaterialsChartId" class="h-80"></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 xl:grid-cols-12">
                        <div class="rounded-xl border border-cyan-600/50 bg-white/95 p-3 shadow xl:col-span-4">
                            <div class="text-sm font-semibold text-gray-800">Stock Composition</div>
                            <div :id="donutChartId" class="h-72"></div>
                        </div>

                        <div class="rounded-xl border border-cyan-600/50 bg-white/95 p-3 shadow xl:col-span-4">
                            <div class="mb-1 text-sm font-semibold text-gray-800">Inbound Trend per Month</div>
                            <div class="mb-2 flex gap-1 text-[11px]">
                                <button type="button" class="rounded border px-2 py-1" :class="monthWindow === 3 ? 'border-cyan-600 bg-cyan-600 text-white' : 'border-gray-300 text-gray-700'" @click="setMonthWindow(3)">3M</button>
                                <button type="button" class="rounded border px-2 py-1" :class="monthWindow === 6 ? 'border-cyan-600 bg-cyan-600 text-white' : 'border-gray-300 text-gray-700'" @click="setMonthWindow(6)">6M</button>
                                <button type="button" class="rounded border px-2 py-1" :class="monthWindow === 12 ? 'border-cyan-600 bg-cyan-600 text-white' : 'border-gray-300 text-gray-700'" @click="setMonthWindow(12)">12M</button>
                            </div>
                            <div :id="lineChartId" class="h-64"></div>
                        </div>

                        <div class="rounded-xl border border-cyan-600/50 bg-white/95 p-3 shadow xl:col-span-4">
                            <div class="text-sm font-semibold text-gray-800">Aging Risk % (&gt;90 hari)</div>
                            <div :id="riskChartId" class="h-72"></div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-cyan-600/50 bg-white/95 p-3 shadow">
                        <div class="text-sm font-semibold text-gray-800">Aging Bucket Available Pieces</div>
                        <div :id="agingChartId" class="h-64"></div>
                    </div>
                </main>
            </div>
        </div>
    </x-filament::section>

    @once
        <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
        <script>
            window.stokBarChartWidget = function (config) {
                return {
                    chartBaseId: config.chartBaseId,
                    barChartId: `${config.chartBaseId}-bar`,
                    topMaterialsChartId: `${config.chartBaseId}-top-materials`,
                    donutChartId: `${config.chartBaseId}-donut`,
                    lineChartId: `${config.chartBaseId}-line`,
                    agingChartId: `${config.chartBaseId}-aging`,
                    riskChartId: `${config.chartBaseId}-risk`,
                    kpiCards: config.kpiCards,
                    categories: config.categories,
                    availableSeries: config.availableSeries,
                    bookedSeries: config.bookedSeries,
                    usedSeries: config.usedSeries,
                    compositionSeries: config.compositionSeries,
                    inboundMonths: config.inboundMonths,
                    inboundSeries: config.inboundSeries,
                    inboundQtySeries: config.inboundQtySeries,
                    topMaterialsCategories: config.topMaterialsCategories,
                    topMaterialsSeries: config.topMaterialsSeries,
                    agingLabels: config.agingLabels,
                    agingSeries: config.agingSeries,
                    riskSeries: config.riskSeries,
                    barMode: 'grouped',
                    monthWindow: 12,
                    selectedMonthIndex: Math.max(0, (config.inboundMonths || []).length - 1),
                    selectedMonthLabel: '-',
                    selectedInboundCount: '0',
                    selectedInboundQty: '0',
                    barChart: null,
                    topMaterialsChart: null,
                    donutChart: null,
                    lineChart: null,
                    agingChart: null,
                    riskChart: null,
                    renderAll() {
                        if (!window.ApexCharts) {
                            setTimeout(() => this.renderAll(), 200);
                            return;
                        }

                        this.refreshSelectedPeriod();
                        this.renderBarChart();
                        this.renderTopMaterialsChart();
                        this.renderDonutChart();
                        this.renderLineChart();
                        this.renderAgingChart();
                        this.renderRiskChart();
                    },
                    selectMonth(idx) {
                        this.selectedMonthIndex = idx;
                        this.refreshSelectedPeriod();
                    },
                    setMonthWindow(months) {
                        this.monthWindow = months;
                        this.renderLineChart();
                        this.renderRiskChart();
                    },
                    refreshSelectedPeriod() {
                        this.selectedMonthLabel = this.inboundMonths[this.selectedMonthIndex] || '-';
                        this.selectedInboundCount = Number(this.inboundSeries[this.selectedMonthIndex] || 0).toLocaleString();
                        this.selectedInboundQty = Number(this.inboundQtySeries[this.selectedMonthIndex] || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
                    },
                    setBarMode(mode) {
                        this.barMode = mode;
                        if (this.barChart) {
                            this.barChart.updateOptions({
                                chart: {
                                    stacked: mode === 'stacked',
                                },
                                plotOptions: {
                                    bar: {
                                        columnWidth: mode === 'stacked' ? '65%' : '55%',
                                    },
                                },
                            });
                        }
                    },
                    renderBarChart() {
                        const target = document.getElementById(this.barChartId);
                        if (!target) return;

                        if (this.barChart) {
                            this.barChart.destroy();
                        }

                        this.barChart = new ApexCharts(target, {
                            chart: {
                                type: 'bar',
                                height: 360,
                                stacked: this.barMode === 'stacked',
                                animations: {
                                    enabled: true,
                                    easing: 'easeinout',
                                    speed: 700,
                                },
                                toolbar: { show: true },
                            },
                            series: [
                                { name: 'Available', data: this.availableSeries },
                                { name: 'Booked', data: this.bookedSeries },
                                { name: 'Used', data: this.usedSeries },
                            ],
                            xaxis: {
                                categories: this.categories,
                                labels: {
                                    rotate: -35,
                                    trim: true,
                                },
                            },
                            tooltip: {
                                y: {
                                    formatter: (val) => `${val} m`,
                                },
                            },
                            yaxis: {
                                title: { text: 'Total Panjang (m)' },
                            },
                            dataLabels: { enabled: false },
                            colors: ['#10b981', '#f59e0b', '#3b82f6'],
                            plotOptions: {
                                bar: {
                                    horizontal: false,
                                    columnWidth: '55%',
                                    borderRadius: 4,
                                },
                            },
                            legend: {
                                position: 'top',
                            },
                        });

                        this.barChart.render();
                    },
                    renderTopMaterialsChart() {
                        const target = document.getElementById(this.topMaterialsChartId);
                        if (!target) return;

                        if (this.topMaterialsChart) {
                            this.topMaterialsChart.destroy();
                        }

                        this.topMaterialsChart = new ApexCharts(target, {
                            chart: {
                                type: 'bar',
                                height: 320,
                                toolbar: { show: false },
                            },
                            series: [{ name: 'Available', data: this.topMaterialsSeries }],
                            xaxis: {
                                categories: this.topMaterialsCategories,
                                labels: { show: false },
                            },
                            yaxis: {
                                labels: {
                                    formatter: (value) => `${value} m`,
                                },
                            },
                            plotOptions: {
                                bar: {
                                    horizontal: true,
                                    borderRadius: 4,
                                    distributed: false,
                                },
                            },
                            colors: ['#0e7490'],
                            dataLabels: {
                                enabled: true,
                                formatter: (val) => `${val} m`,
                            },
                            grid: {
                                borderColor: '#e5e7eb',
                            },
                        });

                        this.topMaterialsChart.render();
                    },
                    renderDonutChart() {
                        const target = document.getElementById(this.donutChartId);
                        if (!target) return;

                        if (this.donutChart) {
                            this.donutChart.destroy();
                        }

                        this.donutChart = new ApexCharts(target, {
                            chart: {
                                type: 'donut',
                                height: 320,
                                toolbar: { show: true },
                            },
                            labels: ['Available', 'Booked', 'Used'],
                            series: this.compositionSeries,
                            colors: ['#10b981', '#f59e0b', '#3b82f6'],
                            legend: {
                                position: 'bottom',
                            },
                            dataLabels: {
                                enabled: true,
                                formatter: (val) => `${val.toFixed(1)}%`,
                            },
                            tooltip: {
                                y: {
                                    formatter: (val) => `${val} m`,
                                },
                            },
                            plotOptions: {
                                pie: {
                                    donut: {
                                        size: '58%',
                                        labels: {
                                            show: true,
                                            total: {
                                                show: true,
                                                label: 'Total',
                                                formatter: (w) => `${w.globals.seriesTotals.reduce((a, b) => a + b, 0).toFixed(2)} m`,
                                            },
                                        },
                                    },
                                },
                            },
                            title: {
                                text: 'Available / Booked / Used',
                                align: 'left',
                            },
                        });

                        this.donutChart.render();
                    },
                    renderLineChart() {
                        const target = document.getElementById(this.lineChartId);
                        if (!target) return;

                        if (this.lineChart) {
                            this.lineChart.destroy();
                        }

                        const startIndex = Math.max(0, this.inboundMonths.length - this.monthWindow);
                        const labels = this.inboundMonths.slice(startIndex);
                        const countData = this.inboundSeries.slice(startIndex);
                        const qtyData = this.inboundQtySeries.slice(startIndex);

                        this.lineChart = new ApexCharts(target, {
                            chart: {
                                type: 'line',
                                height: 320,
                                toolbar: { show: true },
                                zoom: { enabled: true },
                            },
                            series: [
                                {
                                    name: 'Inbound Count',
                                    data: countData,
                                },
                                {
                                    name: 'Inbound Qty',
                                    data: qtyData,
                                },
                            ],
                            stroke: {
                                width: 3,
                                curve: 'smooth',
                            },
                            markers: {
                                size: 4,
                                hover: {
                                    size: 6,
                                },
                            },
                            colors: ['#0ea5e9', '#14b8a6'],
                            xaxis: {
                                categories: labels,
                            },
                            yaxis: {
                                min: 0,
                                forceNiceScale: true,
                            },
                            fill: {
                                type: 'gradient',
                                gradient: {
                                    shadeIntensity: 0.2,
                                    opacityFrom: 0.45,
                                    opacityTo: 0.1,
                                    stops: [0, 90, 100],
                                },
                            },
                            tooltip: {
                                y: {
                                    formatter: (val, opts) => {
                                        return opts.seriesIndex === 0
                                            ? `${val} inbound`
                                            : `${val} qty`;
                                    },
                                },
                            },
                            title: {
                                text: 'Tren Inbound per Bulan',
                                align: 'left',
                            },
                        });

                        this.lineChart.render();
                    },
                    renderAgingChart() {
                        const target = document.getElementById(this.agingChartId);
                        if (!target) return;

                        if (this.agingChart) {
                            this.agingChart.destroy();
                        }

                        this.agingChart = new ApexCharts(target, {
                            chart: {
                                type: 'bar',
                                height: 260,
                                toolbar: { show: false },
                            },
                            series: [{ name: 'Pieces', data: this.agingSeries }],
                            xaxis: {
                                categories: this.agingLabels,
                            },
                            colors: ['#f59e0b'],
                            dataLabels: { enabled: true },
                            plotOptions: {
                                bar: {
                                    columnWidth: '45%',
                                    borderRadius: 4,
                                },
                            },
                        });

                        this.agingChart.render();
                    },
                    renderRiskChart() {
                        const target = document.getElementById(this.riskChartId);
                        if (!target) return;

                        if (this.riskChart) {
                            this.riskChart.destroy();
                        }

                        const startIndex = Math.max(0, this.inboundMonths.length - this.monthWindow);
                        const labels = this.inboundMonths.slice(startIndex);
                        const data = this.riskSeries.slice(startIndex);

                        this.riskChart = new ApexCharts(target, {
                            chart: {
                                type: 'area',
                                height: 300,
                                toolbar: { show: false },
                            },
                            series: [{ name: 'Aging Risk', data }],
                            xaxis: { categories: labels },
                            yaxis: {
                                min: 0,
                                max: 100,
                                labels: {
                                    formatter: (v) => `${v}%`,
                                },
                            },
                            stroke: { curve: 'smooth', width: 2.5 },
                            colors: ['#dc2626'],
                            fill: {
                                type: 'gradient',
                                gradient: {
                                    opacityFrom: 0.35,
                                    opacityTo: 0.05,
                                    stops: [0, 90, 100],
                                },
                            },
                            tooltip: {
                                y: {
                                    formatter: (v) => `${v}%`,
                                },
                            },
                        });

                        this.riskChart.render();
                    },
                };
            };
        </script>
    @endonce
</x-filament-widgets::widget>
