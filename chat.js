/*
 *	JavaScript for chat/plain (http://confetto.s31.xrea.com/)
 *	Copyright (c) 2003-2012 confetto. <confetto@s31.xrea.com>
 *	This script is written in japanese, and encoded in UTF-8.
 *
 *	$Name: release-3-0 $
 *	$Id: chat.js,v 3.7 2012/06/03 19:25:48 confetto Exp $
 */

/********************************** 初期設定 **********************************/

/* リロード間隔(秒単位) */
var RELOAD_INTERVAL = 10;

/* 保持する発言の数 */
var MESSAGES_MAX = 100;

/* HTTPリクエストのタイムアウト (ミリ秒) */
var REQUEST_TIMEOUT = 10 * 1000;	// 10秒

/* 新しい発言の背景色 */
var BACKGROUND_FADE_COLOR = 0xffff33;	// 黄色

/* 新しい発言の背景色がフェードアウトする時間 (ミリ秒) */
var BACKGROUND_FADE_DELAY = 3 * 1000;	// 3秒

/******************************************************************************/

/**
 * 基底オブジェクト。
 */
function Base() {}

Base.prototype = {
	/**
	 * objectの全プロパティを自身にコピーして自身を拡張する。
	 *
	 * @param {Object} object コピー元のオブジェクト
	 * @return {Base} 自身のオブジェクト
	 */
	extend : function(object) {
		for (var property in object)
			if (object.hasOwnProperty(property))
				this[property] = object[property];

		// MSIEでtoString、valueOfが列挙されないバグ。
		// 参考: http://webreflection.blogspot.jp/2007/07/
		//       quick-fix-internet-explorer-and.html
		if (/\bMSIE\b/.test(navigator.userAgent)) {
			['toString', 'valueOf'].forEach(function(perperty) {
				if (object.hasOwnProperty(perperty))
					this[perperty] = object[perperty];
			}, this);
		}

		return this;
	},
	
	/**
	 * 自身をプロトタイプ継承したオブジェクトを生成する。
	 * 参考: http://javascript.crockford.com/prototypal.html
	 *
	 * @return {Base} 自身をプロトタイプ継承したオブジェクト。
	 */
	begetObject : function() {
		function F() {}
		F.prototype = this;
		return new F();
	}
};

/******************************************************************************/

/**
 * 参考: http://developer.mozilla.org/ja/JavaScript/Reference/Global_Objects/
 *       Array/map#.E4.BA.92.E6.8F.9B.E6.80.A7
 */
if (!Array.prototype.map) {
	Array.prototype.map = function(iterator, context) {
		if (this == null)
			throw new TypeError('this is null or not defined');
		
		if ({}.toString.call(iterator) != '[object Function]')
			throw new TypeError(iterator + ' is not a function');
		
		var self = Object(this);
		var length = self.length >>> 0;
		var result = new Array(length);
		
		for (var i = 0; i < length; i++) if (i in self)
			result[i] = iterator.call(context || undefined, self[i], i, self);
		
		return result;
	};
}

/**
 * 参考: http://developer.mozilla.org/ja/JavaScript/Reference/Global_Objects/
 *       Array/forEach#.E4.BA.92.E6.8F.9B.E6.80.A7
 */
if (!Array.prototype.forEach) {
	Array.prototype.forEach = function(iterator, context) {
		if (this == null)
			throw new TypeError('this is null or not defined');
		
		if ({}.toString.call(iterator) != '[object Function]')
			throw new TypeError(iterator + ' is not a function');
		
		var self = Object(this);
		var length = self.length >>> 0;
		
		for (var i = 0; i < length; i++) if (i in self)
			iterator.call(context || undefined, self[i], i, self);
	};
}

/**
 * 参考: http://developer.mozilla.org/en/JavaScript/Reference/Global_Objects/
 *       Function/bind#Compatibility
 */
if (!Function.prototype.bind) {
	Function.prototype.bind = function(context) {
		if (typeof this !== 'function')
			throw new TypeError('what is trying to be bound is not callable');
		
		var args = Array.prototype.slice.call(arguments, 1);
		var self = this;
		var dummy = function() {};
		var bound = function() {
			return self.apply(
				this instanceof dummy ? this : context || window,
				args.concat(Array.prototype.slice.call(arguments))
			);
		};
		dummy.prototype = this.prototype;
		bound.prototype = new dummy();
		return bound;
	};
}

var JSON = JSON || {};

/**
 * 場当たり的な代用品。
 */
if (!JSON.parse) {
	JSON.parse = function(text, reviver) {
		if (reviver)
			throw Error('reviver function is not supported');
		return eval('(' + text + ')');
	};
}

/******************************************************************************/

/**
 * MSIEのためのEventオブジェクト。
 */
function EventIE() {}

EventIE.prototype = Base.prototype.begetObject().extend({
	stopPropagation : function() {
		window.event.cancelBubble = true;
	},
	preventDefault : function() {
		window.event.returnValue = false;
	},
	initEvent : function(type, bubbles, cancelable) {
		this.extend({
			type : type,
			bubbles : bubbles,
			cancelable : cancelable
		});
	}
});

/**
 * MSIEのためのDocumentEvent#createEvent()メソッド。
 */
if (!document.createEvent && window.event !== undefined)
	document.createEvent = function() { return new EventIE() };

/**
 * MSIEのためのXMLHttpRequestオブジェクト。
 *
 * 参考: http://la.ma.la/blog/diary_200509031529.htm
 */
if (window.ActiveXObject) {
	var XMLHttpRequest = (function() {
		/** 状態によって値が変わるプロパティ。 */
		var states = [
			'readyState',
			'responseText',
			'responseXML',
			'status',
			'statusText'
		];

		/**
		 * @param {XMLHttpRequest} self
		 * @param {String} method
		 * @param {Arguments|Array} args
		 */
		function callNative(self, method, args) {
			var params = Array.prototype.map.call(args,
				function(arg, index) { return 'args[' + index + ']' });
			with ({ self : self, args : args })
				return eval('self.__xmlhttp.' + method + '(' + params + ')');
		}

		function construct() {
			this.__xmlhttp = new ActiveXObject('Microsoft.XMLHTTP');
			this.__xmlhttp.onreadystatechange = function() {
				states.forEach(function(property) {
					try { this[property] = this.__xmlhttp[property] } catch(e) {}
				}, this);
				if (this.onreadystatechange)
					this.onreadystatechange();
			}.bind(this);
		}

		construct.prototype = Base.prototype.begetObject().extend({
			onreadystatechange : null,
			readyState : 0,
			responseText : '',
			responseXML : null,
			status : 0,
			statusText : '',

			abort : function() {
				callNative(this, 'abort', arguments);
				XMLHttpRequest.call(this);
				states.forEach(function(property) {
					delete this[property];
				}, this);
			},

			open : function() {
				this.abort();
				callNative(this, 'open', arguments);
			}
		});

		// その他のメソッドは単純にラップする。
		[
			'getAllResponseHeaders',
			'getResponseHeader',
			'send',
			'setRequestHeader'
		].forEach(function(name) {
			construct.prototype[name] = function() {
				return callNative(this, name, arguments);
			};
		});

		return construct;
	})();
}

/**
 * MSIE互換のイベント操作関数群。
 */
var Event = (function() {
	var STRAGE_NAME = '__$wrappers$__';
	
	/**
	 * @param {EventTarget} target
	 * @param {String} type
	 */
	function getWrappers(target, type) {
		if (!target[STRAGE_NAME])
			target[STRAGE_NAME] = {};
		
		if (!target[STRAGE_NAME][type])
			target[STRAGE_NAME][type] = [];
		
		return target[STRAGE_NAME][type];
	}
	
	/**
	 * MSIEのためのイベントリスナを生成する。
	 *
	 * @param {EventTarget} target
	 * @param {String} type
	 * @param {Function} listener
	 * @return {Function}
	 */
	function createWrapper(target, type, listener) {
		var wrapper = function() {
			var event = new EventIE();
			event.initEvent(window.event.type, true, true);
			event.target = window.event.srcElement;
			event.currentTarget = target;
			listener(event);
		};
		wrapper.wrapped = listener;
		getWrappers(target, type).push(wrapper);
		return wrapper;
	}
	
	/**
	 * MSIEのためのイベントリスナを削除する。
	 *
	 * @param {EventTarget} target
	 * @param {String} type
	 * @param {Function} listener
	 * @return {Function}
	 */
	function removeWrapper(target, type, listener) {
		var wrappers = getWrappers(target, type);
		for (var i = 0; i < wrappers.length; i++)
			if (wrappers[i].wrapped === listener)
				return wrappers.splice(i, 1)[0];
		return null;
	}
	
	return {
		/**
		 * @param {EventTarget} target
		 * @param {String} type
		 * @param {Function} listener
		 */
		add : function(target, type, listener) {
			if (target.addEventListener) {
				target.addEventListener(type, listener, false);
			} else /*if (target.attachEvent)*/ {	// MSIE5+
				this.remove(target, type, listener);	// 重複防止
				target.attachEvent('on' + type,
					createWrapper(target, type, listener));
			}
		},
		
		/**
		 * @param {EventTarget} target
		 * @param {String} type
		 * @param {Function} listener
		 */
		remove : function(target, type, listener) {
			if (target.removeEventListener) {
				target.removeEventListener(type, listener, false);
			} else /*if (target.detachEvent)*/ {	// MSIE5+
				// nullが返っても大丈夫らしい。
				target.detachEvent('on' + type,
					removeWrapper(target, type, listener));
			}
		},
		
		/**
		 * @param {EventTarget} target
		 * @param {Event} event
		 */
		dispatch : function(target, event) {
			if (target.dispatchEvent) {
				target.dispatchEvent(event);
			} else /*if (target.fireEvent)*/ {	// MSIE5.5+
				var e = document.createEventObject();
				e.srcElement = target;
				if (target.fireEvent('on' + event.type, e))
					if (target[event.type])
						target[event.type]();
			}
		}
	};
})();

/******************************************************************************/

/**
 * JsonMLパーサ (簡易版) 。
 * 参考: http://jsonml.org/
 */
var JsonML = JsonML || {
	parse : function(jml, filter) {
		if (jml instanceof Array && 'string' === typeof jml[0]) {
			if (jml[0]) {
				var element = document.createElement(jml[0]);
				for (var i = 1; i < jml.length; i++) {
					if (jml[i] instanceof Array || 'object' !== typeof jml[i])
						element.appendChild(JsonML.parse(jml[i], filter));
					else /*if ('object' === typeof jml[i])*/
						for (var name in jml[i])
							if (jml[i].hasOwnProperty(name))
								element.setAttribute(name, jml[i][name]);
				}
				return filter ? filter(element) : element;
			} else {
				var fragment = document.createDocumentFragment();
				for (var i = 1; i < jml.length; i++)
					fragment.appendChild(JsonML.parse(jml[i], filter));
				return fragment;
			}
		} else if ('string' === typeof jml) {
			return document.createTextNode(jml);
		} else {
			throw new SyntaxError('invalid JsonML: ' + jml);
		}
	}
};

/******************************************************************************/

/**
 * FORM要素を操作する関数群。
 */
var FormElement = {
	/**
	 * クエリ文字列を生成する。
	 *
	 * @param {HTMLFormElement} form FORM要素
	 * @return 生成されたクエリ文字列
	 */
	createQuery : function(form) {
		var pairs = [];
		Array.prototype.forEach.call(form.elements, function(element) {
			if (!element.name)
				return;
			
			switch (element.nodeName.toLowerCase()) {
				case 'input':
					switch (element.type) {
						case 'text':
						case 'hidden':
							pairs.push([element.name, element.value]);
							break;
						case 'submit':
							break;
						default:
							throw new Error('unsupported control type: ' + element.type);
					}
					break;
			}
		});
		
		return pairs.map(function(pair) {
			return pair.map(encodeURIComponent).join('=');
		}).join('&');
	},

	/**
	 * submit型イベントを発生させる。
	 *
	 * @param {HTMLFormElement} form FORM要素
	 */
	simulateSubmit : function(form) {
		var event = document.createEvent('HTMLEvents');
		event.initEvent('submit', true, true);
		Event.dispatch(form, event);
	}
};

/******************************************************************************/

/**
 * @param {Number} count カウントの初期値。
 */
function CountDownTimer(count) {
	var current = count;
	var timer = null;
	var self = this;
	
	/** カウントダウンとイベント発動。 */
	function decrease() {
		self.ondecrease(--current);
		if (current <= 0) {
			self.stop();
			self.onexpire();
		}
	}
	
	this.extend({
		/** カウントの開始。 */
		start : function() {
			if (timer)
				throw new Error('timer is already started');
			
			timer = setInterval(decrease, this.interval);
		},
		
		/** カウントの一時停止 */
		pause : function() {
			if (timer) {
				clearInterval(timer);
				timer = null;
			}
		},
		
		/** カウントの初期化。 */
		reset : function() { current = count }
	});
}

CountDownTimer.prototype = Base.prototype.begetObject().extend({
	/** ミリ秒単位でのカウントの間隔。 */
	interval : 1000,
	
	/** カウントの停止。 */
	stop : function() {
		this.pause();
		this.reset();
	},
	
	/** カウント満了イベント。 */
	onexpire : function() {},
	
	/**
	 * カウント減少イベント。
	 *
	 * @param {Number} current 現在のカウント値。
	 */
	ondecrease : function(current) {}
});

/******************************************************************************/

/**
 * HTTPリクエスト。
 *
 * @param {String} url
 */
function HttpRequest(url) {
	var request = new XMLHttpRequest;
	var headers = [['X-Requested-With', 'XMLHttpRequest']];
	var handlers = [[], [], [], [], []];
	var timer;
	
	request.onreadystatechange = function() {
		function callHandler(handler) { handler(request) }
		handlers[request.readyState].forEach(callHandler);
	};
	
	handlers[4].push(function() { clearTimeout(timer) });
	
	this.extend({
		/**
		 * リクエストを送る。前回のリクエストが未完なら送らない。
		 *
		 * @return {Boolean} 前回のリクエストが未完ならfalse、さもなくばtrue。
		 */
		send : function(query) {
			if (4 !== request.readyState && 0 !== request.readyState)
				return false;
			
			timer = setTimeout(request.abort.bind(request), this.timeout);
			request.open(this.method, url, true);
			headers.forEach(function(header) {
				request.setRequestHeader(header[0], header[1]);
			});
			request.send(query);
			return true;
		},
		
		/**
		 * リクエストヘッダを追加する。
		 *
		 * @param {String} name フィールド名。
		 * @param {String} value フィールドの値。
		 */
		addHeader : function(name, value) { headers.push([name, value]) },
		
		/**
		 * readyState変化のハンドラを追加する。
		 * 各ハンドラにはXMLHttpRequestオブジェクトが渡される。
		 *
		 * @param {Number} state readyStateの値。
		 * @param {Function} handler ハンドラ関数。
		 */
		addHandler : function(state, handler) { handlers[state].push(handler) }
	});
}

HttpRequest.prototype = Base.prototype.begetObject().extend({
	/** リクエストメソッド */
	method : 'post',
	
	/** ミリ秒単位でのタイムアウト */
	timeout : 10 * 1000	// 10秒
});

/******************************************************************************/

function ChatRequest(form) {
	HttpRequest.call(this, form.action);
	var self = this;
	
	function onSuccess(request) {
		var type = request.getResponseHeader('Content-Type');
		switch (type.replace(/;.*/, '')) {
			case 'text/javascript':
				eval(request.responseText);
				break;
			case 'application/json':
				self.onLoad(JSON.parse(request.responseText));
				break;
			default:
				throw new Error('unknown content type: ' + type);
		}
	}
	
	this.addHandler(0, function() { alert('通信がタイムアウトしました。') });
	
	this.addHandler(1, function() { defaultStatus = '通信を開始します。' });
	
	this.addHandler(4, function(request) {
		defaultStatus = '通信が完了しました。';
		switch (request.status) {
			case 200:
				onSuccess(request);
				break;
			case 204:
			case 1223:	// MSIE bug
			case 0:		// Opera bug
				defaultStatus = '新しい発言はありません。';
				break;
			default:
				alert(request.status + ' ' + request.statusText);
		}
	});
	
	this.addHeader('Content-Type', 'application/x-www-form-urlencoded');
	
	this.extend({
		start : function() {
			if (!this.send(FormElement.createQuery(form)))
				defaultStatus = 'まだ通信できません。';
		},
		
		onLoad : function(response) {}
	});
}

ChatRequest.prototype = HttpRequest.prototype.begetObject();

/******************************************************************************/

/**
 * @param {Number} red 赤 (0..255)
 * @param {Number} green 緑 (0..255)
 * @param {Number} blue 青 (0..255)
 * @param {Number} alpha 不透明度 (0..1)
 */
function RGBA(red, green, blue, alpha) {
	return this.extend({
		'red'   : red,
		'green' : green,
		'blue'  : blue,
		'alpha' : alpha
	});
}

RGBA.prototype = Base.prototype.begetObject().extend({
	/**
	 * @param {CSSPrimitiveValue} value
	 * @return {RGBA}
	 */
	fromCSSPrimitiveValue : function(value) {
		if (value.primitiveType !== CSSPrimitiveValue.CSS_RGBCOLOR)
			throw new Error('unsupported primitive: ' + value.primitiveType);
		
		var color = value.getRGBColorValue();
		for (var i in this) if (this.hasOwnProperty(i))
			this[i] = color[i].getFloatValue(CSSPrimitiveValue.CSS_NUMBER);
		return this;
	},
	
	/**
	 * CSSの色値から読み込む。
	 *
	 * @param {String} text
	 * @return {RGBA|null} 自身のオブジェクト。読み込めなければnull。
	 */
	fromCSSText : function(text) {
		if ('transparent' === text)	// Firefox, Opera
			return RGBA.apply(this, [0, 0, 0, 0]);
		
		var matched;
		// Firefox, Opera, Chrome
		if ((matched = /^rgba\((\d+), (\d+), (\d+), ([\d.]+)\)$/.exec(text)))
			return RGBA.apply(this, matched.slice(1).map(parseFloat));
		// Firefox, Opera 11.5, Chrome
		else if ((matched = /^rgb\((\d+), (\d+), (\d+)\)$/.exec(text)))
			return RGBA.apply(this, matched.slice(1).map(parseFloat).concat(1));
		// Opera 11
		else if ((matched = /^#([0-9a-f]{6})$/.exec(text)))
			return this.fromNumber(parseInt(matched[1], 16));
		else
			return null;
	},
	
	/**
	 * 数値から読み込む。
	 *
	 * @param {Number} number 色を表す数値 (0xrrggbb)。
	 * @return {RGBA}
	 */
	fromNumber : function(number) {
		var rgb = [16, 8, 0].map(function(o) { return (number >> o) & 0xff });
		return RGBA.apply(this, rgb.concat(1));
	},
	
	/**
	 * CSSの色値を生成する。
	 *
	 * @return {String} rgba(r,g,b,a)形式の色値。
	 */
	toString : function() {
		var rgb = [this.red, this.green, this.blue].map(Math.round);
		return 'rgba(' + rgb.concat(this.alpha).join(',') + ')';
	},
	
	/**
	 * 指定された色に指定された割合だけ近づけた色を返す。
	 *
	 * @param {RGB} target
	 * @param {Number} rate 0以上1以下の割合。
	 * @return {RGBA}
	 */
	fadeTo : function(target, rate) {
		var color = new RGBA().extend(this);
		for (var i in color) if (color.hasOwnProperty(i))
			color[i] += (target[i] - this[i]) * rate;
		return color;
	}
});

/******************************************************************************/

/**
 * 要素の背景色をフェイドアウトする。
 *
 * @param {RGBA} fromColor 背景色
 * @param {Number} delay 何ミリ秒で消えるか
 */
function BackgroundFader(fromColor, delay) {
	var interval = 100;
	var frames = Math.round(delay / interval);
	
	/**
	 * 要素の背景色を取得する。
	 *
	 * @param {Element} element
	 * @return {RGBA}
	 */
	function getBackgroundColor(element) {
		var style = document.defaultView ?
			document.defaultView.getComputedStyle(element, null) :
			element.currentStyle;	// MSIE
		return new RGBA().fromCSSText(style.backgroundColor);
	}

	/**
	 * 要素の背景色を設定する。
	 *
	 * @param {Element} element
	 * @param {RGBA} color
	 */
	function setBackgroundColor(element, color) {
		if (/\bMSIE\b/.test(navigator.userAgent)) {	// MSIEはfilterで代用。
			var argb = [255 * color.alpha, color.red, color.green, color.blue];
			var text = '#' + argb.map(function(c) {
				return ('0' + Math.round(c).toString(16)).slice(-2);
			}).join('');
			element.style.zoom = '1';
			element.style.filter =
			'progid:DXImageTransform.Microsoft.gradient(GradientType=0,' +
			'startColorstr="' + text + '", endColorstr="' + text + '")';
		} else {
			element.style.setProperty('background-color', color, null);
		}
	}
	
	function getTargetColor(element) {
		var color = getBackgroundColor(element);
		// 背景色がtransparentなら、不透明度以外はスケールしない。
		if (color && color.alpha === 0)
			color = fromColor.begetObject().extend({ alpha : 0 });
		return color;
	}
	
	this.extend({
		/**
		 * フェイドアウトを開始する。
		 *
		 * @param {Element} element
		 */
		start : function(element) {
			var targetColor = getTargetColor(element);
			if (!targetColor)
				return;
			
			setBackgroundColor(element, fromColor);
			new CountDownTimer(frames).extend({
				interval : interval,
				ondecrease : function(count) {
					var rate = (frames - count) / frames;
					var color = fromColor.fadeTo(targetColor, rate);
					setBackgroundColor(element, color);
				},
				onexpire : function() {
					if (/\bMSIE\b/.test(navigator.userAgent)) {
						element.style.zoom = 'normal';
						element.style.removeAttribute('filter');
					}
				}
			}).start();
		}
	});
}

BackgroundFader.prototype = Base.prototype.begetObject();

/******************************************************************************/

/**
 * 入室画面
 */
function ChatEntrance() {
	/**
	 * フォームを検証する。
	 *
	 * @param {Event} event
	 */
	function validateForm(event) {
		var form = event.currentTarget;
		if (form.elements.name.value === '') {
			alert('名前を入力してください。');
			form.elements.name.focus();
			event.preventDefault();
		}
	}
	
	this.extend({
		/**
		 * 画面を初期化する。
		 */
		initialize : function() {
			var form = document.getElementsByTagName('form')[0];
			if (!form)
				throw new Error('Cannot find form element');
			
			Event.add(form, 'submit', validateForm);
			form.elements.name.focus();
		}
	});
}

ChatEntrance.prototype = Base.prototype.begetObject();

/******************************************************************************/

/**
 * 発言画面
 */
function ChatRoom() {
	var fadeColor = new RGBA().fromNumber(BACKGROUND_FADE_COLOR);
	var fader = new BackgroundFader(fadeColor, BACKGROUND_FADE_DELAY);
	var form;
	var request;
	
	/**
	 * document.getElementById()へのショートカット。
	 */
	function $(id) { return document.getElementById(id) }
	
	/**
	 * ノードの中身を削除する。
	 *
	 * @param {Node} node 中身を削除する親ノード。
	 * @param {Node} from 削除の基点の子ノード。省略したら最初の子。
	 * @return {Node} 中身が削除された親ノード。
	 */
	function deleteContents(node, from) {
		if (document.createRange) {
			var range = document.createRange();
			range.setStartBefore(from || node.firstChild);
			range.setEndAfter(node.lastChild);
			range.deleteContents();
		} else {	// MSIE
			var child = from || node.firstChild;
			while (child) {
				var next = child.nextSibling;
				node.removeChild(child);
				child = next;
			}
		}
		return node;
	}
	
	/**
	 * 画面を更新する。
	 *
	 * @param {Object} response
	 */
	function update(response) {
		// 発言を更新する。
		var fragment = document.createDocumentFragment();
		response.messages.forEach(function(message) {
			var attribute = message.isInfo ? { 'class' : 'info' } : {};
			[
				['dt', attribute, message.name],
				['dd', attribute,
					message.message,
					['span',
						{ 'class' : 'message-date' },
						'(' + message.date + ')'
					]
				]
			].forEach(function(jml) {
				var element = JsonML.parse(jml);
				try {	// for MSIE bug
					element.style.color = message.color;
				} catch (e) {}
				fragment.appendChild(element);
				setTimeout(function() { fader.start(element) }, 0); // 暫定
			});
		});
		$('message-list').insertBefore(fragment, $('message-list').firstChild);
		
		var dt = $('message-list').getElementsByTagName('dt');
		if (dt[MESSAGES_MAX])
			deleteContents($('message-list'), dt[MESSAGES_MAX]);
		
		// 参加者名の一覧を更新する。
		response.sessions.forEach(function(session) {
			var li = JsonML.parse(['li', session.name]);
			try {	// for MSIE bug
				fragment.appendChild(li).style.color = session.color;
			} catch (e) {}
		});
		deleteContents($('session-list')).appendChild(fragment);
		
		// 参加者の数を更新する。
		fragment = document.createTextNode(response.sessions.length);
		deleteContents($('session-count')).appendChild(fragment);
	}
	
	/**
	 * 発言する。
	 *
	 * @param {Event} event
	 */
	function speak(event) {
		request.start();
		form.elements.mssg.value = '';
		event.preventDefault();
	}
	
	/**
	 * 退室する。
	 */
	function quit(event) { Event.remove(form, 'submit', speak) }
	
	/**
	 * フォームを送信する。
	 */
	function submitForm() {
		if (form.elements.mssg.value === '')
			FormElement.simulateSubmit(form);
	}
	
	/**
	 * フォームの入力を監視する。
	 *
	 * @param {Number} count カウントダウンタイマの現在の値。
	 */
	function observeInput(count) {
		defaultStatus = count;
		if (form.elements.mssg.value !== '')
			this.reset();
	}
	
	this.extend({
		/**
		 * 画面を初期化する。
		 */
		initialize : function() {
			if (!(form = document.getElementsByTagName('form')[0]))
				throw new Error('Cannot find form element');
			
			var timer = new CountDownTimer(RELOAD_INTERVAL);
			timer.extend({ onexpire : submitForm, ondecrease : observeInput });
			Event.add(form, 'submit', timer.stop.bind(timer));
			timer.start();
			
			var isOpera8 = /\bOpera[\/\s]8/.test(navigator.userAgent);
			if (window.XMLHttpRequest && !isOpera8) {
				request = new ChatRequest(form);
				request.extend({ timeout : REQUEST_TIMEOUT, onLoad : update });
				request.addHandler(4, timer.start.bind(timer));
				Event.add(form, 'submit', speak);
				Event.add(form.elements.quit, 'click', quit);
			}
			form.elements.mssg.focus();
		}
	});
}

ChatRoom.prototype = Base.prototype.begetObject();

/******************************************************************************/

/*
 * メインルーチン
 */
(function() {
	var form = document.getElementsByTagName('form')[0];
	if (form) {
		if (form.elements.mssg)	// 発言画面
			var chat = new ChatRoom();
		else if (form.elements.name)	// 入室画面
			var chat = new ChatEntrance();
		
		if (chat)
			chat.initialize();
	}
})();

/*
 * 吉野追加スクリプト
 */

function changeColor() {
    var e = document.getElementById("color");
    e.style.color = e.options[e.selectedIndex].style.color;
}
