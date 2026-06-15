// 通用工具函数 + API 封装
(function(global){
  var API_URL = 'api.php';

  function qs(name){
    var m = window.location.search.match(new RegExp('[?&]' + name + '=([^&]+)'));
    return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : '';
  }

  function api(action, params, cb){
    params = params || {};
    params.action = action;
    var body = [];
    for (var k in params) {
      if (params.hasOwnProperty(k)) {
        body.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k] === undefined || params[k] === null ? '' : params[k]));
      }
    }
    try {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', API_URL, true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
      xhr.onreadystatechange = function(){
        if (xhr.readyState === 4) {
          try {
            var json = JSON.parse(xhr.responseText);
            cb && cb(json);
          } catch (e) {
            cb && cb({ ok: false, error: '服务器响应异常: ' + xhr.status + ' / ' + xhr.responseText.substring(0, 80) });
          }
        }
      };
      xhr.onerror = function(){
        cb && cb({ ok: false, error: '网络连接失败，请检查PHP服务是否启动' });
      };
      xhr.send(body.join('&'));
    } catch (e) {
      cb && cb({ ok: false, error: '请求异常: ' + e.message });
    }
  }

  function fmtTime(s){
    if (!s) return '';
    return String(s).replace('T', ' ').substring(0, 16);
  }

  function nowLocal(){
    var d = new Date();
    var pad = function(n){ return n < 10 ? '0' + n : '' + n; };
    return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + ' '
         + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }

  function $(id){ return document.getElementById(id); }

  global.WT = { api: api, qs: qs, fmtTime: fmtTime, nowLocal: nowLocal, $: $ };
})(window);
