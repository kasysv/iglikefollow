import './bootstrap';
import Alpine from 'alpinejs';
import { formatTotalTwd } from './quantity-total.js';

window.Alpine = Alpine;

/*
 * 商品頁試算用的金額函式。
 *
 * ⛔ 掛在 window 上，是為了讓 Blade 的 x-data 呼叫「同一份」實作——那一份
 * 有 Node 可直接執行的回歸（resources/js/quantity-total.test.js）。若改成在
 * inline script 裡再寫一次，就會有第二套沒人測的算法，而金額正是最不該存在
 * 兩套算法的地方。
 */
window.formatTotalTwd = formatTotalTwd;

Alpine.start();
