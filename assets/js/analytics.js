/**
 * File: assets/js/analytics.js
 * Purpose: Chart.js initialisation for Orders Analytics page.
 * Last modified: v3.4.87
 */
document.addEventListener('DOMContentLoaded', function () {
    var brand = (window.ddAnalytics && window.ddAnalytics.brandColor) || '#65040d';
    var d     = window.ddAnalyticsData || {};

    function hexToRgba(hex, alpha) {
        var r = parseInt(hex.slice(1,3),16),
            g = parseInt(hex.slice(3,5),16),
            b = parseInt(hex.slice(5,7),16);
        return 'rgba('+r+','+g+','+b+','+alpha+')';
    }

    function fmtRwf(v) {
        if (v >= 1000000) return (v/1000000).toFixed(1)+'M';
        if (v >= 1000)    return (v/1000).toFixed(0)+'K';
        return v;
    }

    var yRwf = {
        ticks: { callback: function(v){ return fmtRwf(v)+' RWF'; }, font:{size:11} },
        grid:  { color:'rgba(0,0,0,0.04)' }
    };

    // 1. Revenue chart
    var revCtx = document.getElementById('ddRevenueChart');
    if (revCtx && d.revenue && d.revenue.labels.length) {
        new Chart(revCtx, {
            type: 'bar',
            data: {
                labels: d.revenue.labels,
                datasets:[{ data: d.revenue.data, backgroundColor: hexToRgba(brand,0.85),
                    borderRadius:4, borderSkipped:false }]
            },
            options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} },
                scales:{ x:{grid:{display:false},ticks:{font:{size:11}}}, y: yRwf } }
        });
    } else if (revCtx) {
        revCtx.closest('.dd-chart-wrap').innerHTML = '<p class="dd-empty-state" style="padding-top:80px;text-align:center">No delivered orders in this period.</p>';
    }

    // 2. Status donut
    var statusCtx = document.getElementById('ddStatusChart');
    if (statusCtx && d.status && d.status.labels.length) {
        var statusColors = { pending:'#F59E0B', confirmed:'#3B82F6', ready:'#8B5CF6',
                             delivered:'#10B981', cancelled:'#EF4444', auto_cancelled:'#9CA3AF' };
        var sColors = d.status.labels.map(function(l){ return statusColors[l] || '#CBD5E1'; });
        new Chart(statusCtx, {
            type:'doughnut',
            data:{ labels: d.status.labels.map(function(l){ return l.replace('_',' '); }),
                   datasets:[{data:d.status.data, backgroundColor:sColors, borderWidth:2, borderColor:'#fff'}] },
            options:{ responsive:true, maintainAspectRatio:false,
                plugins:{ legend:{position:'bottom', labels:{font:{size:12}, padding:12}} } }
        });
    }

    // 3. Peak hours bar
    var peakCtx = document.getElementById('ddPeakChart');
    if (peakCtx && d.peak) {
        var hourLabels = [];
        for (var h=0;h<24;h++) {
            hourLabels.push(h===0?'12am':(h<12?h+'am':(h===12?'12pm':(h-12)+'pm')));
        }
        new Chart(peakCtx, {
            type:'bar',
            data:{ labels:hourLabels,
                   datasets:[{data:d.peak.data, backgroundColor:hexToRgba(brand,0.7),
                       borderRadius:3, borderSkipped:false}] },
            options:{ responsive:true, maintainAspectRatio:false,
                plugins:{legend:{display:false}},
                scales:{ x:{grid:{display:false},ticks:{font:{size:10}}},
                         y:{grid:{color:'rgba(0,0,0,0.04)'},ticks:{stepSize:1,font:{size:11}}} } }
        });
    }

    // 4. Customer donut (new vs returning)
    var custCtx = document.getElementById('ddCustomerChart');
    if (custCtx && d.customer && (d.customer.new + d.customer.returning) > 0) {
        new Chart(custCtx, {
            type:'doughnut',
            data:{ labels:['New','Returning'],
                   datasets:[{ data:[d.customer.new, d.customer.returning],
                       backgroundColor:[hexToRgba(brand,0.4), brand],
                       borderWidth:2, borderColor:'#fff' }] },
            options:{ responsive:true, maintainAspectRatio:false,
                plugins:{ legend:{position:'bottom', labels:{font:{size:12},padding:12}} } }
        });
    }

    // 5. Speed trend line chart
    var speedCtx = document.getElementById('ddSpeedTrendChart');
    if (speedCtx && d.speedTrend && d.speedTrend.labels.length > 1) {
        new Chart(speedCtx, {
            type:'line',
            data:{ labels:d.speedTrend.labels,
                   datasets:[{ data:d.speedTrend.data, borderColor:brand,
                       backgroundColor:hexToRgba(brand,0.08), fill:true,
                       tension:0.3, pointRadius:3, borderWidth:2 }] },
            options:{ responsive:true, maintainAspectRatio:false,
                plugins:{ legend:{display:false},
                    tooltip:{ callbacks:{ label:function(c){ return c.parsed.y+' min'; } } } },
                scales:{
                    x:{ grid:{display:false}, ticks:{font:{size:11}} },
                    y:{ grid:{color:'rgba(0,0,0,0.04)'},
                        ticks:{ callback:function(v){return v+' min';}, font:{size:11} },
                        suggestedMin:0 }
                }
            }
        });
    }
});
