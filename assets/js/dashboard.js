/**
 * Kasir Ibtidaiyah - Dashboard JavaScript
 * Chart.js initialization & dashboard interactions
 */

document.addEventListener('DOMContentLoaded', function() {
    initSalesChart();
});

// =============================================
// SALES CHART (using Chart.js CDN)
// =============================================
function initSalesChart() {
    const canvas = document.getElementById('salesChart');
    if (!canvas) return;
    
    // Load Chart.js from CDN
    if (typeof Chart === 'undefined') {
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
        script.onload = function() {
            createSalesChart(canvas);
        };
        document.head.appendChild(script);
    } else {
        createSalesChart(canvas);
    }
}

function createSalesChart(canvas) {
    const ctx = canvas.getContext('2d');
    
    // Gradient fill - Dark Brown (#5c4033)
    const gradient = ctx.createLinearGradient(0, 0, 0, 280);
    gradient.addColorStop(0, 'rgba(92, 64, 51, 0.4)');
    gradient.addColorStop(1, 'rgba(92, 64, 51, 0.02)');
    
    const labels = window.chartLabels || ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
    const values = window.chartValues || [0, 0, 0, 0, 0, 0, 0];
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Penjualan',
                data: values,
                borderColor: '#5c4033', // Dark Brown
                backgroundColor: gradient,
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#5c4033',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7,
                pointHoverBackgroundColor: '#402a22',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    display: false,
                },
                tooltip: {
                    backgroundColor: '#1f2937',
                    titleColor: '#f9fafb',
                    bodyColor: '#d1d5db',
                    borderColor: '#374151',
                    borderWidth: 1,
                    padding: 12,
                    cornerRadius: 8,
                    displayColors: false,
                    callbacks: {
                        label: function(context) {
                            return 'Rp ' + new Intl.NumberFormat('id-ID').format(context.raw);
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false,
                    },
                    ticks: {
                        color: '#9ca3af',
                        font: { size: 12, family: 'Inter' }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.04)',
                    },
                    ticks: {
                        color: '#9ca3af',
                        font: { size: 11, family: 'Inter' },
                        callback: function(value) {
                            if (value >= 1000000) return 'Rp ' + (value / 1000000).toFixed(1) + 'jt';
                            if (value >= 1000) return 'Rp ' + (value / 1000).toFixed(0) + 'rb';
                            return 'Rp ' + value;
                        }
                    }
                }
            }
        }
    });
}
