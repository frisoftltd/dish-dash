( function () {
    'use strict';

    document.addEventListener( 'DOMContentLoaded', function () {
        var canvas = document.getElementById( 'dd-revenue-chart' );
        if ( ! canvas || ! window.ddChartData || ! window.Chart ) return;

        var brandColor = ( window.ddDashboard && window.ddDashboard.brandColor )
            ? window.ddDashboard.brandColor
            : '#65040d';

        new Chart( canvas, {
            type: 'bar',
            data: {
                labels: window.ddChartData.labels,
                datasets: [ {
                    label: 'Revenue (RWF)',
                    data: window.ddChartData.revenue,
                    backgroundColor: brandColor,
                    borderRadius: 5,
                    borderSkipped: false,
                    hoverBackgroundColor: brandColor,
                } ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function ( ctx ) {
                                return ' ' + Number( ctx.parsed.y ).toLocaleString() + ' RWF';
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: '#9ca3af', font: { size: 12 } },
                    },
                    y: {
                        grid: { color: '#f3f4f6' },
                        border: { display: false },
                        beginAtZero: true,
                        ticks: {
                            color: '#9ca3af',
                            font: { size: 12 },
                            precision: 0,
                            callback: function ( v ) {
                                if ( ! Number.isInteger( v ) ) return null;
                                if ( v >= 1000000 ) return ( v / 1000000 ).toFixed( 1 ) + 'M';
                                if ( v >= 1000 )    return Math.round( v / 1000 ) + 'K';
                                return v;
                            },
                        },
                    },
                },
            },
        } );

        if ( window.ddChartData.revenue.every( function(v) { return v === 0; } ) ) {
            var chartCard = document.querySelector( '.dd-chart-card' );
            if ( chartCard ) chartCard.style.display = 'none';
        }
    } );
} )();
