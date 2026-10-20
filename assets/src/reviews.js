/**
 * Enspire Reviews – front-end.
 *
 * Every [data-er-instance] wrapper keeps its own state in data-* attributes, so several
 * blocks can live on one page. Lists, filters, sorting and pagination load through
 * GET /reviews; writes go through the REST API with the X-WP-Nonce header.
 */
( function () {
	'use strict';

	var cfg = window.enspireReviews || {};
	var i18n = cfg.i18n || {};

	function t( key, n ) {
		var s = i18n[ key ] || '';
		return typeof n === 'number' ? s.replace( '%d', String( n ) ) : s;
	}

	/** Build an endpoint URL that works with pretty and plain (?rest_route=) permalinks. */
	function endpoint( path, params ) {
		var url = new URL( cfg.root, window.location.href );
		if ( url.searchParams.has( 'rest_route' ) ) {
			url.searchParams.set( 'rest_route', url.searchParams.get( 'rest_route' ).replace( /\/$/, '' ) + '/' + path );
		} else {
			url.pathname = url.pathname.replace( /\/$/, '' ) + '/' + path;
		}
		Object.keys( params || {} ).forEach( function ( key ) {
			if ( params[ key ] !== undefined && params[ key ] !== null && params[ key ] !== '' ) {
				url.searchParams.set( key, params[ key ] );
			}
		} );
		return url.toString();
	}

	function errorMessage( json ) {
		if ( json && json.code === 'rest_cookie_invalid_nonce' ) {
			return t( 'sessionExpired' );
		}
		if ( json && json.data && json.data.params ) {
			var msgs = Object.keys( json.data.params ).map( function ( k ) {
				return json.data.params[ k ];
			} );
			if ( msgs.length ) {
				return msgs.join( ' ' );
			}
		}
		return ( json && json.message ) || t( 'error' );
	}

	function request( method, path, opts ) {
		opts = opts || {};
		var headers = { Accept: 'application/json' };
		var useNonce = !! cfg.nonce && opts.nonce !== false;
		if ( useNonce ) {
			headers[ 'X-WP-Nonce' ] = cfg.nonce;
		}
		Object.assign( headers, opts.headers || {} );

		var init = { method: method, credentials: 'same-origin', headers: headers };
		if ( opts.body ) {
			headers[ 'Content-Type' ] = 'application/json';
			init.body = JSON.stringify( opts.body );
		}

		return fetch( endpoint( path, opts.params ), init ).then( function ( res ) {
			return res
				.json()
				.catch( function () {
					return {};
				} )
				.then( function ( json ) {
					if ( res.ok ) {
						return json;
					}
					// A cached page can carry a stale nonce; public reads work without one.
					if ( method === 'GET' && useNonce && json && json.code === 'rest_cookie_invalid_nonce' ) {
						return request( method, path, Object.assign( {}, opts, { nonce: false } ) );
					}
					var err = new Error( errorMessage( json ) );
					err.status = res.status;
					err.code = json && json.code;
					throw err;
				} );
		} );
	}

	function setMessage( el, text, type ) {
		if ( ! el ) {
			return;
		}
		el.textContent = text || '';
		el.classList.remove( 'er-msg--error', 'er-msg--success' );
		if ( text && type ) {
			el.classList.add( 'er-msg--' + type );
		}
	}

	function resetCaptcha( form ) {
		try {
			var ts = form.querySelector( '.cf-turnstile' );
			if ( ts && window.turnstile ) {
				window.turnstile.reset( ts );
			}
			var rc = form.querySelector( '.g-recaptcha' );
			if ( rc && window.grecaptcha ) {
				var all = Array.prototype.slice.call( document.querySelectorAll( '.g-recaptcha' ) );
				window.grecaptcha.reset( all.indexOf( rc ) );
			}
		} catch ( e ) {
			// Widget not ready yet; nothing to reset.
		}
	}

	/* ---------------------------------------------------------------------
	 * Instance
	 * ------------------------------------------------------------------ */

	function Instance( el ) {
		this.el = el;
		this.busy = false;
		this.ownerView = el.hasAttribute( 'data-er-owner-view' );

		el.addEventListener( 'click', this.onClick.bind( this ) );
		el.addEventListener( 'change', this.onChange.bind( this ) );
		el.addEventListener( 'submit', this.onSubmit.bind( this ) );
	}

	Instance.prototype.state = function () {
		var d = this.el.dataset;
		return {
			object_type: d.erType,
			object_id: d.erId,
			rating: d.erRating || 'all',
			sort: d.erSort || 'newest',
			page: parseInt( d.erPage || '1', 10 ),
			per_page: d.erPerPage,
			pagination: d.erPagination,
		};
	};

	Instance.prototype.hasList = function () {
		return !! ( this.el.dataset.erType && this.el.dataset.erId );
	};

	Instance.prototype.$ = function ( sel ) {
		return this.el.querySelector( sel );
	};

	Instance.prototype.announce = function ( text ) {
		var live = this.$( '[data-er-live]' );
		if ( live ) {
			live.textContent = '';
			window.setTimeout( function () {
				live.textContent = text;
			}, 50 );
		}
	};

	Instance.prototype.setBusy = function ( busy ) {
		this.busy = busy;
		this.el.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
	};

	/* Events ------------------------------------------------------------ */

	Instance.prototype.onClick = function ( e ) {
		var btn = e.target.closest( 'button' );
		if ( ! btn || ! this.el.contains( btn ) ) {
			return;
		}
		var item = btn.closest( '[data-er-review]' );

		if ( btn.hasAttribute( 'data-er-filter' ) ) {
			this.setFilter( btn.getAttribute( 'data-er-filter' ) );
		} else if ( btn.hasAttribute( 'data-er-more' ) ) {
			this.load( this.state().page + 1, true );
		} else if ( btn.hasAttribute( 'data-er-page' ) ) {
			this.load( parseInt( btn.getAttribute( 'data-er-page' ), 10 ), false, true );
		} else if ( btn.hasAttribute( 'data-er-reply-toggle' ) && item ) {
			this.toggleReply( item, btn );
		} else if ( btn.hasAttribute( 'data-er-reply-cancel' ) && item ) {
			this.toggleReply( item, item.querySelector( '[data-er-reply-toggle]' ), false );
		} else if ( btn.hasAttribute( 'data-er-reply-delete' ) && item ) {
			this.deleteReply( item );
		} else if ( btn.hasAttribute( 'data-er-report' ) && item ) {
			this.report( item, btn );
		} else if ( btn.hasAttribute( 'data-er-delete-own' ) ) {
			this.deleteOwn( btn.closest( '[data-er-form]' ) );
		}
	};

	Instance.prototype.onChange = function ( e ) {
		if ( e.target.matches( '[data-er-sort]' ) ) {
			this.el.dataset.erSort = e.target.value;
			this.load( 1, false );
		}
	};

	Instance.prototype.onSubmit = function ( e ) {
		var form = e.target;
		if ( form.matches( '[data-er-form]' ) ) {
			e.preventDefault();
			this.submitReview( form );
		} else if ( form.matches( '[data-er-reply-form]' ) ) {
			e.preventDefault();
			this.submitReply( form );
		}
	};

	/* List -------------------------------------------------------------- */

	Instance.prototype.setFilter = function ( value ) {
		this.el.dataset.erRating = value;
		this.syncFilters();
		this.load( 1, false );
	};

	Instance.prototype.syncFilters = function () {
		var current = this.el.dataset.erRating || 'all';
		this.el.querySelectorAll( '[data-er-filter]' ).forEach( function ( b ) {
			b.setAttribute( 'aria-pressed', b.getAttribute( 'data-er-filter' ) === current ? 'true' : 'false' );
		} );
	};

	Instance.prototype.load = function ( page, append, scroll ) {
		if ( ! this.hasList() || this.busy ) {
			return Promise.resolve();
		}
		var self = this;
		var params = this.state();
		params.page = page;
		this.setBusy( true );

		return request( 'GET', 'reviews', { params: params } )
			.then( function ( json ) {
				var list = self.$( '[data-er-list]' );
				if ( list ) {
					if ( append ) {
						list.insertAdjacentHTML( 'beforeend', json.html );
					} else {
						list.innerHTML = json.html;
					}
				}
				var empty = self.$( '[data-er-empty]' );
				if ( empty ) {
					empty.hidden = json.total > 0;
					empty.textContent = params.rating === 'all' ? t( 'emptyAll' ) : t( 'emptyFiltered' );
				}
				var pag = self.$( '[data-er-region="pagination"]' );
				if ( pag ) {
					pag.innerHTML = json.pagination || '';
				}
				self.el.dataset.erPage = String( json.page );
				self.announce( t( 'loaded', list ? list.children.length : 0 ) );
				if ( scroll ) {
					var top = self.$( '[data-er-region="list"]' ) || self.el;
					top.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
			} )
			.catch( function ( err ) {
				self.announce( err.message );
			} )
			.finally( function () {
				self.setBusy( false );
			} );
	};

	/** Refresh summary, breakdown and filter counts after a write. */
	Instance.prototype.applySummary = function ( summary ) {
		if ( ! summary ) {
			return;
		}
		var s = this.$( '[data-er-region="summary"]' );
		if ( s && summary.html ) {
			s.innerHTML = summary.html;
		}
		var b = this.$( '[data-er-region="breakdown"]' );
		if ( b && summary.breakdown_html ) {
			b.innerHTML = summary.breakdown_html;
		}

		var pos = parseInt( this.el.dataset.erPositive || '4', 10 );
		var neg = parseInt( this.el.dataset.erNegative || '2', 10 );
		var counts = { all: summary.count || 0, positive: 0, negative: 0 };
		Object.keys( summary.breakdown || {} ).forEach( function ( star ) {
			var n = summary.breakdown[ star ];
			star = parseInt( star, 10 );
			if ( star >= pos ) {
				counts.positive += n;
			}
			if ( star <= neg ) {
				counts.negative += n;
			}
		} );
		this.el.querySelectorAll( '[data-er-count]' ).forEach( function ( el ) {
			var key = el.getAttribute( 'data-er-count' );
			if ( key in counts ) {
				el.textContent = counts[ key ].toLocaleString();
			}
		} );
		this.syncFilters();
	};

	/* Review form ------------------------------------------------------- */

	Instance.prototype.submitReview = function ( form ) {
		var self = this;
		var msg = form.querySelector( '[data-er-message]' );
		var submit = form.querySelector( '[data-er-submit]' );
		var data = new FormData( form );
		var rating = parseInt( data.get( 'rating' ) || '0', 10 );
		var content = String( data.get( 'content' ) || '' ).trim();
		var area = form.querySelector( 'textarea[name="content"]' );
		var min = parseInt( area.getAttribute( 'minlength' ) || '0', 10 );
		var max = parseInt( area.getAttribute( 'maxlength' ) || '0', 10 );

		if ( ! rating ) {
			setMessage( msg, t( 'ratingRequired' ), 'error' );
			return;
		}
		if ( min && content.length < min ) {
			setMessage( msg, t( 'tooShort', min ), 'error' );
			area.focus();
			return;
		}
		if ( max && content.length > max ) {
			setMessage( msg, t( 'tooLong', max ), 'error' );
			area.focus();
			return;
		}

		var body = {
			object_type: this.el.dataset.erType,
			object_id: parseInt( this.el.dataset.erId, 10 ),
			rating: rating,
			content: content,
			er_website: data.get( 'er_website' ) || '',
			er_ts: data.get( 'er_ts' ) || '',
			captcha_token: data.get( 'cf-turnstile-response' ) || data.get( 'g-recaptcha-response' ) || '',
		};

		submit.disabled = true;
		setMessage( msg, '' );

		request( 'POST', 'reviews', { body: body } )
			.then( function ( json ) {
				setMessage( msg, json.message, 'success' );
				self.setFormMode( form, 'edit', json.review && json.review.id );
				self.applySummary( json.summary );
				self.el.dataset.erRating = 'all';
				self.load( 1, false );
			} )
			.catch( function ( err ) {
				setMessage( msg, err.message, 'error' );
			} )
			.finally( function () {
				submit.disabled = false;
				resetCaptcha( form );
			} );
	};

	Instance.prototype.setFormMode = function ( form, mode, id ) {
		form.dataset.erMode = mode;
		form.dataset.erReviewId = id ? String( id ) : '';
		var title = form.querySelector( '[data-er-form-title]' );
		if ( title ) {
			title.textContent = mode === 'edit' ? t( 'editTitle' ) : t( 'writeTitle' );
		}
		var submit = form.querySelector( '[data-er-submit]' );
		if ( submit ) {
			submit.textContent = mode === 'edit' ? t( 'update' ) : t( 'submit' );
		}
		var del = form.querySelector( '[data-er-delete-own]' );
		if ( del ) {
			del.hidden = mode !== 'edit';
		}
	};

	Instance.prototype.deleteOwn = function ( form ) {
		if ( ! form || ! form.dataset.erReviewId || ! window.confirm( t( 'confirmDelete' ) ) ) {
			return;
		}
		var self = this;
		var msg = form.querySelector( '[data-er-message]' );

		request( 'DELETE', 'reviews/' + form.dataset.erReviewId )
			.then( function ( json ) {
				form.reset();
				self.setFormMode( form, 'create', null );
				setMessage( msg, json.message || t( 'deleted' ), 'success' );
				self.applySummary( json.summary );
				self.load( 1, false );
			} )
			.catch( function ( err ) {
				setMessage( msg, err.message, 'error' );
			} );
	};

	/* Reply / report ---------------------------------------------------- */

	Instance.prototype.toggleReply = function ( item, toggle, force ) {
		var form = item.querySelector( '[data-er-reply-form]' );
		if ( ! form ) {
			return;
		}
		var open = typeof force === 'boolean' ? force : form.hidden;
		form.hidden = ! open;
		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
		if ( open ) {
			form.querySelector( 'textarea' ).focus();
		} else if ( toggle ) {
			toggle.focus();
		}
	};

	Instance.prototype.replyHeaders = function () {
		return this.ownerView ? { 'X-ER-Owner-View': '1' } : {};
	};

	Instance.prototype.replaceItem = function ( item, html ) {
		var tpl = document.createElement( 'template' );
		tpl.innerHTML = String( html ).trim();
		var fresh = tpl.content.firstElementChild;
		if ( fresh ) {
			item.replaceWith( fresh );
			var focusable = fresh.querySelector( 'button, a' );
			if ( focusable ) {
				focusable.focus();
			}
		}
	};

	Instance.prototype.submitReply = function ( form ) {
		var self = this;
		var item = form.closest( '[data-er-review]' );
		var msg = form.querySelector( '[data-er-message]' );
		var text = String( new FormData( form ).get( 'reply' ) || '' ).trim();
		var btn = form.querySelector( '[type="submit"]' );
		if ( ! text ) {
			return;
		}
		btn.disabled = true;

		request( 'POST', 'reviews/' + item.dataset.erReview + '/reply', {
			body: { reply: text },
			headers: this.replyHeaders(),
		} )
			.then( function ( json ) {
				self.replaceItem( item, json.html );
				self.announce( json.message );
			} )
			.catch( function ( err ) {
				setMessage( msg, err.message, 'error' );
				btn.disabled = false;
			} );
	};

	Instance.prototype.deleteReply = function ( item ) {
		if ( ! window.confirm( t( 'confirmDeleteReply' ) ) ) {
			return;
		}
		var self = this;
		request( 'DELETE', 'reviews/' + item.dataset.erReview + '/reply', { headers: this.replyHeaders() } )
			.then( function ( json ) {
				self.replaceItem( item, json.html );
				self.announce( json.message || t( 'replyDeleted' ) );
			} )
			.catch( function ( err ) {
				self.announce( err.message );
				window.alert( err.message );
			} );
	};

	Instance.prototype.report = function ( item, btn ) {
		if ( ! window.confirm( t( 'confirmReport' ) ) ) {
			return;
		}
		var self = this;
		btn.disabled = true;
		request( 'POST', 'reviews/' + item.dataset.erReview + '/report' )
			.then( function ( json ) {
				btn.textContent = t( 'reported' );
				self.announce( json.message );
			} )
			.catch( function ( err ) {
				if ( err.status === 409 ) {
					btn.textContent = t( 'reported' );
				} else {
					btn.disabled = false;
				}
				self.announce( err.message );
				window.alert( err.message );
			} );
	};

	/* Boot -------------------------------------------------------------- */

	function init() {
		document.querySelectorAll( '[data-er-instance]' ).forEach( function ( el ) {
			if ( ! el.erInstance ) {
				el.erInstance = new Instance( el );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
