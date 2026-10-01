/* OV Content Bridge admin – vanilla JS, no dependencies. */
( function () {
	'use strict';

	var CFG = window.OVCB_DATA || {};
	var LABELS = {
		'created': 'ساخته شد',
		'updated': 'به‌روز شد',
		'skipped': 'رد شد',
		'unchanged': 'بدون تغییر',
		'error': 'خطا',
		'dry-create': 'ساخته خواهد شد',
		'dry-update': 'تغییر خواهد کرد'
	};

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function post( data ) {
		data.append( 'nonce', CFG.nonce );
		return fetch( CFG.ajax, { method: 'POST', credentials: 'same-origin', body: data } )
			.then( function ( r ) {
				return r.text().then( function ( t ) {
					var j;
					try { j = JSON.parse( t ); } catch ( e ) {
						throw new Error( 'پاسخ نامعتبر از سرور (HTTP ' + r.status + '). ' + t.replace( /<[^>]+>/g, ' ' ).trim().slice( 0, 300 ) );
					}
					if ( ! j.success ) {
						var err = new Error( ( j.data && j.data.message ) || 'خطای نامشخص' );
						err.data = j.data;
						throw err;
					}
					return j.data;
				} );
			} );
	}

	function initForm( form ) {
		var card = form.closest( '.ovcb-card' );
		var box = card.querySelector( '.ovcb-result' );
		var bar = box.querySelector( '.ovcb-bar' );
		var summary = box.querySelector( '.ovcb-summary' );
		var warnBox = box.querySelector( '.ovcb-warnings' );
		var actions = box.querySelector( '.ovcb-actions' );
		var tbody = box.querySelector( 'tbody' );
		var button = form.querySelector( '[type=submit]' );
		var counts, running = false;

		function renderSummary( done, total, dry ) {
			var html = '<b>' + ( dry ? 'اجرای آزمایشی' : 'اجرای واقعی' ) + ':</b> ' + done + ' از ' + total;
			Object.keys( counts ).forEach( function ( k ) {
				html += ' <span class="ovcb-badge ' + esc( k ) + '">' + esc( LABELS[ k ] || k ) + ': ' + counts[ k ] + '</span>';
			} );
			summary.innerHTML = html;
		}

		function addRows( rows ) {
			var frag = '';
			rows.forEach( function ( r ) {
				counts[ r.status ] = ( counts[ r.status ] || 0 ) + 1;
				var links = '';
				if ( r.edit ) { links += '<a href="' + esc( r.edit ) + '" target="_blank" rel="noopener">ویرایش</a>'; }
				if ( r.view ) { links += '<a href="' + esc( r.view ) + '" target="_blank" rel="noopener">مشاهده</a>'; }
				frag += '<tr><td>' + esc( r.n ) + '</td><td><span class="ovcb-badge ' + esc( r.status ) + '">' +
					esc( LABELS[ r.status ] || r.status ) + '</span></td><td>' + esc( r.title ) +
					( r.id ? ' <small>#' + esc( r.id ) + '</small>' : '' ) + '</td><td>' + esc( r.msg ) + '</td><td>' + links + '</td></tr>';
			} );
			tbody.insertAdjacentHTML( 'beforeend', frag );
		}

		function run( job, total, dry, offset, retries ) {
			var fd = new FormData();
			fd.append( 'action', 'ovcb_run_job' );
			fd.append( 'job', job );
			fd.append( 'offset', offset );
			return post( fd ).then( function ( d ) {
				addRows( d.results );
				var pct = total ? Math.round( d.next / total * 100 ) : 100;
				bar.style.width = pct + '%';
				renderSummary( d.next, total, dry );
				if ( d.done ) {
					return true;
				}
				return run( job, total, dry, d.next, 2 );
			} ).catch( function ( e ) {
				if ( retries > 0 && ! e.data ) {
					return new Promise( function ( res ) { setTimeout( res, 2000 ); } ).then( function () {
						return run( job, total, dry, offset, retries - 1 );
					} );
				}
				throw e;
			} );
		}

		function start( forceReal ) {
			if ( running ) { return; }
			var fd = new FormData( form );
			if ( forceReal ) { fd.delete( 'dry_run' ); }
			var dry = fd.has( 'dry_run' );
			fd.append( 'action', 'ovcb_create_job' );
			fd.append( 'kind', form.getAttribute( 'data-kind' ) );

			running = true;
			button.disabled = true;
			counts = {};
			box.hidden = false;
			tbody.innerHTML = '';
			warnBox.innerHTML = '';
			actions.innerHTML = '';
			bar.style.width = '0';
			bar.classList.remove( 'is-done' );
			summary.textContent = 'در حال خواندن فایل…';

			post( fd ).then( function ( d ) {
				if ( d.warnings && d.warnings.length ) {
					warnBox.innerHTML = d.warnings.map( esc ).join( '<br>' );
				}
				renderSummary( 0, d.total, d.dry );
				return run( d.job, d.total, d.dry, 0, 2 ).then( function () { return d; } );
			} ).then( function ( d ) {
				bar.classList.add( 'is-done' );
				if ( d.dry ) {
					actions.innerHTML = '<p>اجرای آزمایشی تمام شد و هیچ تغییری ذخیره نشد. اگر گزارش درست است:</p>' +
						'<button type="button" class="button button-primary ovcb-go-real">اجرای واقعی با همین فایل و تنظیمات</button>';
					actions.querySelector( '.ovcb-go-real' ).addEventListener( 'click', function () {
						start( true );
					} );
				} else {
					actions.innerHTML = '<p>✅ انجام شد. در صورت نیاز می‌توانید از تب «تاریخچه و بازگردانی» همه تغییرات این عملیات را برگردانید.</p>';
				}
			} ).catch( function ( e ) {
				var msg = e.message;
				if ( e.data && e.data.warnings && e.data.warnings.length ) {
					msg += '<br>' + e.data.warnings.map( esc ).join( '<br>' );
					warnBox.innerHTML = esc( e.message ) + '<br>' + e.data.warnings.map( esc ).join( '<br>' );
				} else {
					warnBox.textContent = msg;
				}
				summary.textContent = 'متوقف شد.';
			} ).then( function () {
				running = false;
				button.disabled = false;
			} );
		}

		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			start( false );
		} );
	}

	document.querySelectorAll( 'form.ovcb-job-form' ).forEach( initForm );

	document.querySelectorAll( '.ovcb-undo' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			if ( ! window.confirm( 'همه تغییرات این عملیات برگردانده شود؟ موارد تازه ساخته‌شده به زباله‌دان منتقل می‌شوند.' ) ) {
				return;
			}
			btn.disabled = true;
			var fd = new FormData();
			fd.append( 'action', 'ovcb_undo_job' );
			fd.append( 'job', btn.getAttribute( 'data-job' ) );
			post( fd ).then( function ( d ) {
				btn.outerHTML = '<span class="ovcb-badge">' + esc( d.message ) + '</span>';
			} ).catch( function ( e ) {
				btn.disabled = false;
				window.alert( e.message );
			} );
		} );
	} );
}() );
