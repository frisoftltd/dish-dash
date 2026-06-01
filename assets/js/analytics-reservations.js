/**
 * File: assets/js/analytics-reservations.js
 * Purpose: Chart.js initialisation for Reservations Analytics page.
 * Last modified: v3.4.87
 */
document.addEventListener('DOMContentLoaded', function () {
    var brand = (window.ddAnalytics && window.ddAnalytics.brandColor) || '#65040d';
    var d     = window.ddResData || {};

    function hexToRgba(hex, alpha) {
        var r=parseInt(hex.slice(1,3),16), g=parseInt(hex.slice(3,5),16), b=parseInt(hex.slice(5,7),16);
        return 'rgba('+r+','+g+','+b+','+alpha+')';
    }

    // 1. Bookings over time — bar
    var botCtx = document.getElementById('ddResBookingsChart');
    if (botCtx && d.bookings && d.bookings.labels.length) {
        new Chart(botCtx, {
            type:'bar',
            data:{ labels:d.bookings.labels,
                   datasets:[{data:d.bookings.data, backgroundColor:hexToRgba(brand,0.8),
                       borderRadius:4, borderSkipped:false}] },
            options:{ responsive:true, maintainAspectRatio:false,
                plugins:{legend:{display:false}},
                scales:{ x:{grid:{display:false},ticks:{font:{size:11}}},
                         y:{grid:{color:'rgba(0,0,0,0.04)'},ticks:{stepSize:1,font:{size:11}}} } }
        });
    }

    // 2. Status donut
    var statusCtx = document.getElementById('ddResStatusChart');
    if (statusCtx && d.status && d.status.labels.length) {
        var sc = { pending:'#F59E0B', confirmed:'#10B981', cancelled:'#EF4444',
                   no_show:'#6B7280', auto_cancelled:'#9CA3AF', pending_payment:'#3B82F6' };
        new Chart(statusCtx, {
            type:'doughnut',
            data:{ labels:d.status.labels.map(function(l){return l.replace('_',' ');}),
                   datasets:[{data:d.status.data,
                       backgroundColor:d.status.labels.map(function(l){return sc[l]||'#CBD5E1';}),
                       borderWidth:2, borderColor:'#fff'}] },
            options:{ responsive:true, maintainAspectRatio:false,
                plugins:{legend:{position:'bottom',labels:{font:{size:12},padding:12}}} }
        });
    }

    // 3. Day of week bar
    var dowCtx = document.getElementById('ddResDowChart');
    if (dowCtx && d.dow) {
        new Chart(dowCtx, {
            type:'bar',
            data:{ labels:d.dow.labels,
                   datasets:[{data:d.dow.data, backgroundColor:hexToRgba(brand,0.75),
                       borderRadius:4, borderSkipped:false}] },
            options:{ responsive:true, maintainAspectRatio:false,
                plugins:{legend:{display:false}},
                scales:{ x:{grid:{display:false},ticks:{font:{size:12}}},
                         y:{grid:{color:'rgba(0,0,0,0.04)'},ticks:{stepSize:1,font:{size:11}}} } }
        });
    }

    // 4. Party size bar
    var partyCtx = document.getElementById('ddResPartyChart');
    if (partyCtx && d.party) {
        new Chart(partyCtx, {
            type:'bar',
            data:{ labels:d.party.labels,
                   datasets:[{data:d.party.data,
                       backgroundColor:[hexToRgba(brand,0.5),hexToRgba(brand,0.65),
                                        hexToRgba(brand,0.8),brand],
                       borderRadius:4, borderSkipped:false}] },
            options:{ responsive:true, maintainAspectRatio:false,
                plugins:{legend:{display:false}},
                scales:{ x:{grid:{display:false},ticks:{font:{size:12}}},
                         y:{grid:{color:'rgba(0,0,0,0.04)'},ticks:{stepSize:1,font:{size:11}}} } }
        });
    }
});
