/**
 * 体重推移グラフ（measurement-chart.js）で、最新点のツールチップを常時表示する（会員側 S-1402 だけ。2026-10-27 追加）。
 * 設計書 S-1402 設計方針「最新点のツールチップを常時表示する」。
 *
 * Chart.js を読み込まない小さなモジュールに分け、vitest で確かめる（グラフは Chart.js のインスタンスを受け取るだけ）。
 * Chart.js 標準のツールチップを setActiveElements（chart.setActiveElements と chart.tooltip.setActiveElements）で操作する。
 * レーダーチャートなど同じ画面のほかのグラフに影響しないよう、グラフごとのインラインプラグインと options だけで作る
 * （Chart.defaults の変更やグローバルな Chart.register(plugin) は使わない）。
 *
 * - 出すとき：初回の初期アニメーションが終わったとき（animation.onComplete。初回だけ）。
 *   アニメーションの途中で出すと、ツールチップの位置が動いている途中の点の座標で決まり、そこに取り残されるため。
 *   Chart.js の初期化（afterInit）が終わる前に呼ばれた onComplete は数えない（下の「初期化の前の onComplete」）
 * - 戻すとき：イベントのあと（afterEvent）に点が 1 つも選ばれていなければ最新点に戻す（mouseout・点のない場所へのマウス移動やタップ）。
 *   canvas の外をタップしたときは canvas にイベントが届かないので、document の pointerdown（1 回だけ登録）で戻す
 * - リサイズのあと：ツールチップの位置は次のイベントまで計算し直されないので、リサイズによる更新のあと（afterUpdate）に、
 *   その時点で選ばれている点（なければ最新点）を選び直して位置を計算し直す。
 *   options.onResize はレイアウトを計算し直す前に呼ばれるので使わない
 *
 * ## 常時表示の処理で何が起きても、Chart.js 標準のツールチップは壊さない（2026-10-27 不具合修正）
 *
 * - 初期化の前の onComplete：DPR が端数の端末（Android の 2.625 など。Windows の 125% 表示やブラウザのズームも同じ）では、
 *   canvas の幅×DPR が整数にならないため、Chart.js のコンストラクタの中のリサイズ（bindEvents → attached → resize）で
 *   描き直しが起き、アニメーションがないので onComplete がその場で呼ばれる。このときツールチップのプラグインの afterInit は
 *   まだで chart.tooltip が無い。触ると例外になり、コンストラクタごと止まって、標準のツールチップも出なくなっていた。
 *   そのため、自分のプラグインの afterInit（ツールチップのプラグインの afterInit よりあと。インラインプラグインはうしろに並ぶ）
 *   が済むまでの onComplete は何もせず、ready も立てない。コンストラクタの最後の update() で初期アニメーションが始まり、
 *   その終わりの onComplete が「初回」になる
 * - chart.tooltip が無いときは、ツールチップに触る処理はすべて何もしない
 * - 自前のフックの中身は try/catch で包み、例外が出たら console.warn を 1 回だけ出して、そのグラフの常時表示だけをやめる
 *   （以後そのグラフでは何もしない）。Chart.js 本体の処理（イベント・描画・標準のツールチップ）には例外を伝えない
 */

/**
 * 最新点の位置（datasets[0].data のうち y が null でない最後の要素）。なければ -1
 *
 * @param {{y: number|null}[]|undefined} points
 * @returns {number}
 */
export function latestPointIndex(points) {
    if (!Array.isArray(points)) {
        return -1;
    }
    for (let i = points.length - 1; i >= 0; i--) {
        if (points[i] != null && points[i].y != null) {
            return i;
        }
    }

    return -1;
}

/** 選ばれている点が、最新点 1 つだけか */
export function isOnlyLatestActive(active, index) {
    return active.length === 1 && active[0].datasetIndex === 0 && active[0].index === index;
}

/** 点を選び、ツールチップをその点に出す（位置は点の今の座標で計算し直される） */
function activate(chart, elements) {
    chart.setActiveElements(elements);
    const element = chart.getDatasetMeta(0).data[elements[0].index];
    chart.tooltip.setActiveElements(elements, element ? { x: element.x, y: element.y } : undefined);
}

/**
 * 点が 1 つも選ばれていなければ、最新点を選ぶ。選び直したら true（呼ぶ側で描き直す）。
 * すでに最新点が選ばれていれば何もしない（描き直しが繰り返されないように）。ツールチップがまだ無ければ何もしない
 */
export function pinLatestIfIdle(chart) {
    if (!chart.tooltip) {
        return false;
    }
    const index = latestPointIndex(chart.data.datasets[0]?.data);
    if (index < 0) {
        return false;
    }
    const active = chart.tooltip.getActiveElements();
    if (active.length > 0) {
        return false;
    }
    activate(chart, [{ datasetIndex: 0, index }]);

    return true;
}

/** 選ばれている点を最新点に戻す（canvas の外をタップしたとき）。戻したら true。ツールチップがまだ無ければ何もしない */
export function resetToLatest(chart) {
    if (!chart.tooltip) {
        return false;
    }
    const index = latestPointIndex(chart.data.datasets[0]?.data);
    if (index < 0 || isOnlyLatestActive(chart.tooltip.getActiveElements(), index)) {
        return false;
    }
    activate(chart, [{ datasetIndex: 0, index }]);

    return true;
}

/**
 * 最新点を常時表示するグラフ（document の pointerdown で走査する）。
 * 値はそのグラフの guard（例外を封じ込めて処理を動かす関数。createPinLatest の中で作る）
 *
 * @type {Map<object, (fn: () => void) => void>}
 */
const pinnedCharts = new Map();
let outsideTapListening = false;

/** canvas の外をタップ（クリック）したら、そのグラフを最新点に戻す。グラフごとに判定する（例外はグラフごとに封じ込める） */
export function handleOutsidePointerDown(event, charts = pinnedCharts) {
    charts.forEach((guard, chart) => {
        guard(() => {
            if (!chart.canvas || chart.canvas.contains(event.target)) {
                return;
            }
            if (resetToLatest(chart)) {
                chart.render();
            }
        });
    });
}

function listenOutsideTap() {
    if (outsideTapListening) {
        return;
    }
    outsideTapListening = true;
    document.addEventListener('pointerdown', (event) => handleOutsidePointerDown(event));
}

/**
 * 1 つのグラフ用の部品を作る（グラフごとに呼ぶ。状態はグラフごとに持つ）。
 * plugin は Chart の設定の plugins に、onAnimationComplete は options.animation.onComplete に渡す
 */
export function createPinLatest() {
    // Chart.js の初期化（自分のプラグインの afterInit）が済んだか。済むまでは onComplete を数えない
    let initialized = false;
    // 初回の初期アニメーションが終わったか（終わるまでは最新点を出さない）
    let ready = false;
    // リサイズのあと、位置を計算し直す必要があるか
    let resized = false;
    // 例外が出て、このグラフの常時表示をやめたか（やめたら以後は何もしない）
    let disabled = false;
    // 常時表示をやめたときに document の pointerdown の対象から外すため
    let pinnedChart = null;

    /** fn を動かす。例外が出たら warn を 1 回だけ出し、このグラフの常時表示をやめる（Chart.js には例外を伝えない） */
    function guard(fn) {
        if (disabled) {
            return;
        }
        try {
            fn();
        } catch (err) {
            disabled = true;
            if (pinnedChart) {
                pinnedCharts.delete(pinnedChart);
            }
            console.warn('measurement chart: 最新点のツールチップの常時表示をやめました（標準のツールチップはそのまま使えます）', err);
        }
    }

    const plugin = {
        id: 'measurementChartPinLatest',
        afterInit(chart) {
            guard(() => {
                initialized = true;
                pinnedChart = chart;
                pinnedCharts.set(chart, guard);
                listenOutsideTap();
            });
        },
        afterDestroy(chart) {
            pinnedCharts.delete(chart);
        },
        afterEvent(chart, args) {
            // ツールチップ（Chart.js 標準のプラグイン）の afterEvent のあとに呼ばれる（インラインプラグインはうしろに並ぶ）
            guard(() => {
                if (ready && pinLatestIfIdle(chart)) {
                    args.changed = true;
                }
            });
        },
        resize() {
            guard(() => {
                resized = true;
            });
        },
        afterUpdate(chart) {
            guard(() => {
                if (!resized) {
                    return;
                }
                resized = false;
                if (!ready || !chart.tooltip) {
                    return;
                }
                const active = chart.tooltip.getActiveElements();
                if (active.length > 0) {
                    // 選ばれている点はそのままで、位置だけ計算し直す（同じ点だと Chart.js は位置が変わったときだけ更新する）
                    activate(chart, active.map(({ datasetIndex, index }) => ({ datasetIndex, index })));
                } else {
                    pinLatestIfIdle(chart);
                }
            });
        },
    };

    function onAnimationComplete(context) {
        guard(() => {
            // 初期化の前（コンストラクタの中のリサイズからの呼び出し）は数えない。ready も立てない
            if (ready || !initialized) {
                return;
            }
            ready = true;
            const chart = context.chart;
            // 初期アニメーションの間にホバー・タップされていたら、その点のまま
            if (pinLatestIfIdle(chart)) {
                chart.render();
            }
        });
    }

    return { plugin, onAnimationComplete };
}
