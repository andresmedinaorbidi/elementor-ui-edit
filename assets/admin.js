/**
 * AI Elementor Sync admin UI.
 * Uses global aiElementorSync: { rest_url, nonce, site_url, ai_service }.
 */
( function () {
	'use strict';

	const config = typeof aiElementorSync !== 'undefined' ? aiElementorSync : {};
	const restUrl = config.rest_url || '';
	const nonce = config.nonce || '';
	const siteUrl = config.site_url || '';

	var templatesCache = [];
	var lastKitData = null;

	// #region agent log — in WordPress open DevTools (F12) → Console to see these
	function logToDebug( message, data ) {
		if ( typeof console !== 'undefined' && console.warn ) {
			console.warn( '[AI Elementor Sync]', message, data || {} );
		}
	}
	function responseToJson( res ) {
		return res.text().then( function ( text ) {
			var contentType = ( res.headers.get( 'Content-Type' ) || '' ).toLowerCase();
			var looksLikeHtml = text.trim().charAt( 0 ) === '<' || contentType.indexOf( 'text/html' ) !== -1;
			logToDebug( 'REST response', {
				status: res.status,
				url: res.url,
				contentType: contentType,
				bodyLength: text.length,
				startsWithAngle: text.trim().charAt( 0 ) === '<'
			} );
			if ( looksLikeHtml ) {
				var status = res.status;
				var isGatewayOrOrigin = status === 502 || status === 520 || status === 503;
				var msg = isGatewayOrOrigin
					? 'Server returned an error (status ' + status + '). The REST API may be unavailable, or the external AI service may be down. Check Settings → AI service URL and try again.'
					: 'Server returned HTML instead of JSON (status ' + status + '). The REST API may be unavailable, or you may need to log in again.';
				logToDebug( 'HTML response, skipping parse', { status: status, url: res.url } );
				throw new Error( msg );
			}
			try {
				return JSON.parse( text );
			} catch ( e ) {
				logToDebug( 'JSON parse failed', { error: e.message, bodyStart: text.substring( 0, 200 ) } );
				throw e;
			}
		} );
	}
	// #endregion

	function getHeaders( jsonBody ) {
		const headers = {
			'X-WP-Nonce': nonce,
		};
		if ( jsonBody ) {
			headers['Content-Type'] = 'application/json';
		}
		return headers;
	}

	function setResult( elId, content, isError ) {
		const el = document.getElementById( elId );
		if ( ! el ) return;
		el.classList.remove( 'loading', 'success', 'error' );
		if ( isError ) el.classList.add( 'error' );
		else el.classList.add( 'success' );
		el.innerHTML = content;
	}

	function setLoading( elId ) {
		const el = document.getElementById( elId );
		if ( ! el ) return;
		el.classList.add( 'loading' );
		el.textContent = 'Loading…';
	}

	function renderJson( data ) {
		return '<pre>' + ( typeof data === 'string' ? data : JSON.stringify( data, null, 2 ) ) + '</pre>';
	}

	function renderInspectResult( data ) {
		if ( ! data ) return renderJson( data );
		if ( data.code === 'internal_error' ) {
			return '<p class="error"><strong>Server error:</strong> ' + escapeHtml( data.message || 'Unknown error' ) + '</p>' + renderJson( data );
		}
		const imageSlots = data.image_slots;
		let html = '';
		if ( Array.isArray( imageSlots ) && imageSlots.length > 0 ) {
			html += '<div class="ai-elementor-sync-inspect-image-slots">';
			html += '<h3 class="ai-elementor-sync-result-heading">Image slots (' + imageSlots.length + ')</h3>';
			html += '<table class="widefat striped"><thead><tr><th>path</th><th>slot_type</th><th>el_type</th><th>image_url</th><th>image_id</th></tr></thead><tbody>';
			imageSlots.forEach( function ( slot ) {
				const url = ( slot.image_url || '' ).length > 60 ? ( slot.image_url || '' ).substring( 0, 60 ) + '…' : ( slot.image_url || '' );
				html += '<tr><td><code>' + escapeHtml( slot.path || '' ) + '</code></td>';
				html += '<td>' + escapeHtml( slot.slot_type || '' ) + '</td>';
				html += '<td>' + escapeHtml( slot.el_type || '' ) + '</td>';
				html += '<td title="' + escapeHtml( slot.image_url || '' ) + '">' + escapeHtml( url ) + '</td>';
				html += '<td>' + ( slot.image_id != null ? escapeHtml( String( slot.image_id ) ) : '—' ) + '</td></tr>';
			} );
			html += '</tbody></table></div>';
		}
		html += '<div class="ai-elementor-sync-inspect-full"><h3 class="ai-elementor-sync-result-heading">Full response</h3>';
		html += renderJson( data ) + '</div>';
		return html;
	}

	// Load Elementor templates (list-templates) and populate all template dropdowns
	function loadTemplates() {
		var url = restUrl + '/list-templates';
		fetch( url, {
			method: 'GET',
			headers: getHeaders( false ),
			credentials: 'same-origin',
		} )
			.then( function ( res ) {
				return responseToJson( res ).then( function ( data ) {
					return { ok: res.ok, status: res.status, data: data };
				} );
			} )
			.then( function ( result ) {
				var list = result.data;
				if ( ! result.ok || ! list ) {
					templatesCache = [];
					setTemplateSelectOptions( 'Failed to load templates (log in or refresh).', true );
					return;
				}
				// Unwrap if REST returned { data: [...] } or use array as-is
				if ( ! Array.isArray( list ) && list && Array.isArray( list.data ) ) {
					list = list.data;
				}
				templatesCache = Array.isArray( list ) ? list : [];
				if ( templatesCache.length === 0 ) {
					setTemplateSelectOptions( 'No templates found. Create header/footer in Elementor Theme Builder.', false );
				} else {
					var options = '<option value="">— Select template —</option>' +
						templatesCache.map( function ( t ) {
							return '<option value="' + escapeHtml( String( t.id ) ) + '">' + escapeHtml( ( t.name || '' ) + ' (' + ( t.document_type || '' ) + ')' ) + '</option>';
						} ).join( '' );
					[ 'inspect', 'replace', 'llm', 'apply' ].forEach( function ( prefix ) {
						var sel = document.getElementById( prefix + '-template-id' );
						if ( sel ) sel.innerHTML = options;
					} );
				}
			} )
			.catch( function ( err ) {
				templatesCache = [];
				setTemplateSelectOptions( 'Failed to load templates. Retry below.', true );
				logToDebug( 'list-templates failed', { error: ( err && err.message ) || String( err ) } );
			} );
	}

	function setTemplateSelectOptions( message, isError ) {
		var opt = '<option value="">' + escapeHtml( message ) + '</option>';
		[ 'inspect', 'replace', 'llm', 'apply' ].forEach( function ( prefix ) {
			var sel = document.getElementById( prefix + '-template-id' );
			if ( sel ) sel.innerHTML = opt;
		} );
		// Show/hide retry buttons when load failed
		document.querySelectorAll( '.js-retry-templates' ).forEach( function ( el ) {
			el.style.display = isError ? 'inline-block' : 'none';
		} );
	}

	// Build target payload for API: { url } or { template_id }. prefix = 'inspect'|'replace'|'llm'|'apply'
	function getTargetPayload( prefix ) {
		var formEl = prefix === 'llm' ? document.getElementById( 'form-llm-edit' ) : null;
		var radio = formEl ? formEl.querySelector( 'input[name="' + prefix + '-target"]:checked' ) : document.querySelector( 'input[name="' + prefix + '-target"]:checked' );
		var target = radio && radio.value ? String( radio.value ).trim().toLowerCase() : '';
		if ( target === 'kit' && prefix === 'llm' ) {
			return { target: 'kit' };
		}
		if ( target === 'template' ) {
			var sel = document.getElementById( prefix + '-template-id' );
			var id = sel ? parseInt( sel.value, 10 ) : 0;
			if ( id > 0 ) return { template_id: id };
			return null;
		}
		if ( target === 'url' ) {
			var urlEl = document.getElementById( prefix + '-url' );
			var url = urlEl ? ( urlEl.value || '' ).trim() : '';
			if ( url ) return { url: url };
			return null;
		}
		// llm only: 'auto' — resolved in initLlmEdit using instruction
		return null;
	}

	// Detect document_type from instruction text for "Auto" target (footer, header, single)
	function detectDocumentTypeFromInstruction( instruction ) {
		if ( ! instruction || typeof instruction !== 'string' ) return null;
		var lower = instruction.toLowerCase();
		if ( /\bfooter\b/.test( lower ) ) return 'footer';
		if ( /\bheader\b/.test( lower ) ) return 'header';
		if ( /\bsingle\b/.test( lower ) ) return 'single';
		if ( /\bpage\b/.test( lower ) ) return 'page';
		return null;
	}

	// Set visibility of URL / template / auto / kit hint from current target radio. prefix = inspect|replace|llm|apply, hasAuto = whether form has Auto option, hasKit = whether form has Kit option
	function setTargetVisibility( prefix, hasAuto, hasKit ) {
		var checked = document.querySelector( 'input[name="' + prefix + '-target"]:checked' );
		var v = checked ? checked.value : 'url';
		var urlWrap = document.getElementById( prefix + '-url-wrap' );
		var templateWrap = document.getElementById( prefix + '-template-wrap' );
		var autoHint = hasAuto ? document.getElementById( prefix + '-auto-hint' ) : null;
		var kitHint = hasKit ? document.getElementById( prefix + '-kit-hint' ) : null;
		if ( urlWrap ) {
			urlWrap.classList.toggle( 'hidden', v !== 'url' );
			urlWrap.style.display = ( v === 'url' ) ? '' : 'none';
		}
		if ( templateWrap ) {
			templateWrap.classList.toggle( 'hidden', v !== 'template' );
			templateWrap.style.display = ( v === 'template' ) ? '' : 'none';
		}
		if ( autoHint ) {
			autoHint.classList.toggle( 'hidden', v !== 'auto' );
			autoHint.style.display = ( v === 'auto' ) ? '' : 'none';
		}
		if ( kitHint ) {
			kitHint.classList.toggle( 'hidden', v !== 'kit' );
			kitHint.style.display = ( v === 'kit' ) ? '' : 'none';
		}
		// When Auto or Kit is selected, URL is not needed; ensure URL input is not required
		var urlInput = document.getElementById( prefix + '-url' );
		if ( urlInput ) urlInput.removeAttribute( 'required' );
	}

	// Toggle URL vs template wrap when target radio changes
	function initTargetToggles() {
		function toggle( prefix, hasAuto, hasKit ) {
			hasKit = hasKit || false;
			var urlWrap = document.getElementById( prefix + '-url-wrap' );
			var templateWrap = document.getElementById( prefix + '-template-wrap' );
			var autoHint = hasAuto ? document.getElementById( prefix + '-auto-hint' ) : null;
			var kitHint = hasKit ? document.getElementById( prefix + '-kit-hint' ) : null;
			var radios = document.querySelectorAll( 'input[name="' + prefix + '-target"]' );
			radios.forEach( function ( radio ) {
				radio.addEventListener( 'change', function () {
					var v = this.value;
					if ( urlWrap ) {
						urlWrap.classList.toggle( 'hidden', v !== 'url' );
						urlWrap.style.display = ( v === 'url' ) ? '' : 'none';
					}
					if ( templateWrap ) {
						templateWrap.classList.toggle( 'hidden', v !== 'template' );
						templateWrap.style.display = ( v === 'template' ) ? '' : 'none';
					}
					if ( autoHint ) {
						autoHint.classList.toggle( 'hidden', v !== 'auto' );
						autoHint.style.display = ( v === 'auto' ) ? '' : 'none';
					}
					if ( kitHint ) {
						kitHint.classList.toggle( 'hidden', v !== 'kit' );
						kitHint.style.display = ( v === 'kit' ) ? '' : 'none';
					}
					var urlInput = document.getElementById( prefix + '-url' );
					if ( urlInput ) urlInput.removeAttribute( 'required' );
				} );
			} );
			// Set initial visibility from current checked radio
			setTargetVisibility( prefix, hasAuto, hasKit );
		}
		toggle( 'inspect', false, false );
		toggle( 'replace', false, false );
		toggle( 'llm', true, true );
		toggle( 'apply', false, false );
	}

	// Tab switching (optional onShow callback for tab name)
	function initTabs( onTabShow ) {
		const tabs = document.querySelectorAll( '.ai-elementor-sync-tabs .nav-tab' );
		const panels = document.querySelectorAll( '.ai-elementor-sync-panel' );
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				const tabName = this.getAttribute( 'data-tab' );
				tabs.forEach( function ( t ) {
					t.classList.remove( 'nav-tab-active' );
					t.setAttribute( 'aria-selected', 'false' );
				} );
				panels.forEach( function ( panel ) {
					const id = 'tab-' + tabName;
					const isActive = panel.id === id;
					panel.classList.toggle( 'hidden', ! isActive );
				} );
				this.classList.add( 'nav-tab-active' );
				this.setAttribute( 'aria-selected', 'true' );
				if ( onTabShow && typeof onTabShow === 'function' ) {
					onTabShow( tabName );
				}
			} );
		} );
	}

	function loadSettings() {
		var url = restUrl + '/settings';
		logToDebug( 'fetch start', { url: url, restUrl: restUrl }, 'C' );
		fetch( url, {
			method: 'GET',
			headers: getHeaders( false ),
			credentials: 'same-origin',
		} )
			.then( function ( res ) { return responseToJson( res ); } )
			.then( function ( data ) {
				const ai = document.getElementById( 'settings-ai-service-url' );
				const llm = document.getElementById( 'settings-llm-register-url' );
				const sideload = document.getElementById( 'settings-sideload-images' );
				if ( ai && data.ai_service_url !== undefined ) ai.value = data.ai_service_url || '';
				if ( llm && data.llm_register_url !== undefined ) llm.value = data.llm_register_url || '';
				if ( sideload && data.sideload_images !== undefined ) sideload.checked = !! data.sideload_images;
			} )
			.catch( function () {} );
	}

	function loadLog() {
		const container = document.getElementById( 'log-entries' );
		if ( ! container ) return;
		container.textContent = 'Loading…';
		logToDebug( 'fetch start', { url: restUrl + '/log' }, 'C' );
		fetch( restUrl + '/log', {
			method: 'GET',
			headers: getHeaders( false ),
			credentials: 'same-origin',
		} )
			.then( function ( res ) {
				return responseToJson( res ).then( function ( data ) {
					return { ok: res.ok, status: res.status, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok && result.data && result.data.code === 'internal_error' ) {
					container.innerHTML = '<p class="error">Failed to load log: ' + escapeHtml( result.data.message || 'Server error ' + result.status ) + '</p>';
					return;
				}
				const entries = ( result.data && result.data.entries ) || [];
				if ( entries.length === 0 ) {
					container.innerHTML = '<p>No log entries yet.</p>';
					return;
				}
				// Newest last in API; show newest first
				const reversed = entries.slice().reverse();
				container.innerHTML = reversed.map( function ( entry ) {
					const ctx = entry.context && Object.keys( entry.context ).length ? '\n' + JSON.stringify( entry.context, null, 2 ) : '';
					const levelClass = 'log-' + ( entry.level || 'info' );
					return '<div class="ai-elementor-sync-log-entry ' + levelClass + '">' +
						'<span class="log-time">' + escapeHtml( entry.time || '' ) + '</span>' +
						'<span class="log-level">[' + escapeHtml( entry.level || 'info' ) + ']</span> ' +
						escapeHtml( entry.message || '' ) +
						( ctx ? '<div class="log-context">' + escapeHtml( ctx ) + '</div>' : '' ) +
						'</div>';
				} ).join( '' );
			} )
			.catch( function ( err ) {
				container.innerHTML = '<p class="error">Failed to load log: ' + escapeHtml( err.message || String( err ) ) + '</p>';
			} );
	}

	function getKitTestResultEl() {
		var el = document.getElementById( 'result-kit-test' );
		if ( el ) return el;
		var tab = document.getElementById( 'tab-theme' );
		if ( tab ) {
			el = tab.querySelector( '.ai-elementor-sync-result' );
			if ( el ) return el;
		}
		return document.getElementById( 'result-kit-settings' );
	}

	function testKitConnection() {
		var resultEl = getKitTestResultEl();
		var show = function ( html, isError ) {
			var el = getKitTestResultEl();
			if ( el ) {
				el.style.display = '';
				el.className = 'ai-elementor-sync-result ' + ( isError ? 'error' : 'success' );
				el.innerHTML = html;
			}
			if ( typeof console !== 'undefined' && console.log ) {
				console.log( '[AI Elementor Sync] testKitConnection result', isError ? 'error' : 'ok' );
			}
		};
		try {
			if ( ! ( restUrl && restUrl.length > 0 ) ) {
				show( '<p><strong>REST URL not configured.</strong></p><p class="description">Check that aiElementorSync.rest_url is set (e.g. in wp_localize_script).</p>', true );
				return;
			}
			if ( resultEl ) {
				resultEl.style.display = '';
				resultEl.textContent = 'Testing…';
				resultEl.className = 'ai-elementor-sync-result loading';
			}
			var testUrl = restUrl.replace( /\/$/, '' ) + '/kit-settings';
			if ( typeof console !== 'undefined' && console.log ) {
				console.log( '[AI Elementor Sync] testKitConnection running', { restUrl: restUrl, testUrl: testUrl } );
			}
			fetch( testUrl, {
				method: 'GET',
				headers: getHeaders( false ),
				credentials: 'same-origin',
			} )
				.then( function ( res ) {
					return res.text().then( function ( text ) {
						var data = null;
						try {
							data = text ? JSON.parse( text ) : null;
						} catch ( err ) {
							data = { _raw: text };
						}
						return { ok: res.ok, status: res.status, data: data, text: text };
					} );
				} )
				.then( function ( result ) {
					var msg = 'HTTP ' + result.status + ( result.ok ? ' — Plugin can read Elementor theme settings.' : ' — ' + ( result.data && result.data.message ? result.data.message : 'Request failed.' ) );
					var html = '<p><strong>' + escapeHtml( msg ) + '</strong></p>';
					if ( result.data && result.data.kit_id != null ) {
						html += '<p>Kit ID: ' + escapeHtml( String( result.data.kit_id ) ) + '</p>';
					}
					html += '<details><summary>Raw response</summary><pre>' + escapeHtml( typeof result.text === 'string' ? result.text : JSON.stringify( result.data, null, 2 ) ) + '</pre></details>';
					show( html, ! result.ok );
				} )
				.catch( function ( err ) {
					show(
						'<p><strong>Request failed:</strong> ' + escapeHtml( err.message || String( err ) ) + '</p>' +
						'<p class="description">Check that you are logged in and have permission. Open DevTools (F12) → Network to see the request to <code>' + escapeHtml( testUrl ) + '</code>.</p>',
						true
					);
					if ( typeof console !== 'undefined' && console.error ) {
						console.error( '[AI Elementor Sync] testKitConnection error', err );
					}
				} );
		} catch ( err ) {
			show( '<p><strong>Error:</strong> ' + escapeHtml( err.message || String( err ) ) + '</p>', true );
			if ( typeof console !== 'undefined' && console.error ) {
				console.error( '[AI Elementor Sync] testKitConnection threw', err );
			}
		}
	}
	try {
		window.aiElementorSyncTestKit = testKitConnection;
	} catch ( err ) {}

	function loadKitSettings() {
		var resultEl = document.getElementById( 'result-kit-settings' );
		if ( resultEl ) {
			setLoading( 'result-kit-settings' );
		}
		logToDebug( 'fetch start', { url: restUrl + '/kit-settings' }, 'C' );
		fetch( restUrl + '/kit-settings', {
			method: 'GET',
			headers: getHeaders( false ),
			credentials: 'same-origin',
		} )
			.then( function ( res ) {
				return responseToJson( res ).then( function ( data ) {
					return { ok: res.ok, status: res.status, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok && result.data ) {
					lastKitData = result.data;
				} else {
					lastKitData = null;
				}
				var content = renderKitSettingsResult( result.data, result.status );
				var el = document.getElementById( 'result-kit-settings' );
				if ( el ) setResult( 'result-kit-settings', content, ! result.ok );
			} )
			.catch( function ( err ) {
				lastKitData = null;
				var el = document.getElementById( 'result-kit-settings' );
				if ( el ) setResult( 'result-kit-settings', '<p class="error">' + escapeHtml( err.message || String( err ) ) + '</p>', true );
			} );
	}

	function renderKitSettingsResult( data, status ) {
		if ( status === 404 || ( data && data.code === 'no_active_kit' ) ) {
			return '<p class="error">No active Elementor kit found.</p>';
		}
		if ( status >= 500 || ( data && ( data.code === 'invalid_settings' || data.code === 'internal_error' ) ) ) {
			return '<p class="error">' + escapeHtml( ( data && data.message ) || 'Server error.' ) + '</p>' + ( data ? renderJson( data ) : '' );
		}
		if ( ! data ) return renderJson( data );
		var raw = data.raw_settings && typeof data.raw_settings === 'object' ? data.raw_settings : {};
		var colorsList = Array.isArray( raw.system_colors ) ? raw.system_colors : ( Array.isArray( data.colors ) ? data.colors : [] );
		var typographyList = Array.isArray( raw.system_typography ) ? raw.system_typography : ( Array.isArray( data.typography ) ? data.typography : [] );
		var customColors = Array.isArray( raw.custom_colors ) ? raw.custom_colors : [];
		var html = '';
		if ( data.kit_id != null ) {
			html += '<p><strong>Kit ID:</strong> ' + escapeHtml( String( data.kit_id ) ) + '</p>';
		}
		// Colors table: support _id or id, title, value (string or object)
		function colorValue( item ) {
			if ( item == null ) return '';
			var valStr = item.value != null && item.value !== '' ? String( item.value ).trim() : '';
			var colStr = item.color != null && item.color !== '' ? String( item.color ).trim() : '';
			var v = valStr !== '' ? item.value : ( colStr !== '' ? item.color : '' );
			if ( typeof v === 'string' ) return v;
			if ( v && typeof v === 'object' && typeof v.color === 'string' ) return v.color;
			if ( typeof v === 'object' ) return JSON.stringify( v );
			return v != null ? String( v ) : '';
		}
		function colorId( item ) {
			if ( item == null ) return '';
			if ( item._id != null ) return String( item._id );
			if ( item.id != null ) return String( item.id );
			return '';
		}
		function hexForColorInput( hex ) {
			if ( ! hex || typeof hex !== 'string' ) return '#000000';
			hex = hex.trim();
			if ( hex.charAt( 0 ) !== '#' ) hex = '#' + hex;
			if ( hex.length === 7 && /^#[0-9a-fA-F]{6}$/.test( hex ) ) return hex;
			if ( hex.length === 4 && /^#[0-9a-fA-F]{3}$/.test( hex ) ) {
				return '#' + hex.charAt( 1 ) + hex.charAt( 1 ) + hex.charAt( 2 ) + hex.charAt( 2 ) + hex.charAt( 3 ) + hex.charAt( 3 );
			}
			return '#000000';
		}
		var kitFontOptions = [ 'Default', 'system-ui', 'Arial', 'Helvetica', 'Georgia', 'Times New Roman', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Oswald', 'Poppins', 'Source Sans Pro', 'Playfair Display', 'Merriweather' ];
		if ( colorsList.length > 0 ) {
			html += '<div class="ai-elementor-sync-kit-colors" data-list="system_colors"><h3 class="ai-elementor-sync-result-heading">System colors (' + colorsList.length + ')</h3>';
			html += '<table class="widefat striped"><thead><tr><th>id</th><th>title</th><th>value</th></tr></thead><tbody>';
			colorsList.forEach( function ( item ) {
				if ( typeof item === 'object' && item !== null ) {
					var id = colorId( item );
					var hex = hexForColorInput( colorValue( item ) );
					html += '<tr data-id="' + escapeHtml( id ) + '" data-title="' + escapeHtml( item.title || '' ) + '">';
					html += '<td><code>' + escapeHtml( id ) + '</code></td><td>' + escapeHtml( item.title || '' ) + '</td>';
					html += '<td class="kit-color-cell"><input type="color" class="kit-color-input" data-id="' + escapeHtml( id ) + '" value="' + escapeHtml( hex ) + '" title="' + escapeHtml( hex ) + '"><input type="text" class="kit-hex-input" data-id="' + escapeHtml( id ) + '" value="' + escapeHtml( hex ) + '" size="8" maxlength="7" placeholder="#hex"></td>';
					html += '</tr>';
				} else {
					html += '<tr><td colspan="3">' + escapeHtml( JSON.stringify( item ) ) + '</td></tr>';
				}
			} );
			html += '</tbody></table></div>';
		}
		if ( customColors.length > 0 ) {
			html += '<div class="ai-elementor-sync-kit-colors" data-list="custom_colors"><h3 class="ai-elementor-sync-result-heading">Custom colors (' + customColors.length + ')</h3>';
			html += '<table class="widefat striped"><thead><tr><th>id</th><th>title</th><th>value</th></tr></thead><tbody>';
			customColors.forEach( function ( item ) {
				if ( typeof item === 'object' && item !== null ) {
					var id = colorId( item );
					var hex = hexForColorInput( colorValue( item ) );
					html += '<tr data-id="' + escapeHtml( id ) + '" data-title="' + escapeHtml( item.title || '' ) + '">';
					html += '<td><code>' + escapeHtml( id ) + '</code></td><td>' + escapeHtml( item.title || '' ) + '</td>';
					html += '<td class="kit-color-cell"><input type="color" class="kit-color-input" data-id="' + escapeHtml( id ) + '" value="' + escapeHtml( hex ) + '" title="' + escapeHtml( hex ) + '"><input type="text" class="kit-hex-input" data-id="' + escapeHtml( id ) + '" value="' + escapeHtml( hex ) + '" size="8" maxlength="7" placeholder="#hex"></td>';
					html += '</tr>';
				} else {
					html += '<tr><td colspan="3">' + escapeHtml( JSON.stringify( item ) ) + '</td></tr>';
				}
			} );
			html += '</tbody></table></div>';
		}
		// Typography table: collect all keys from items and render as columns; add Edit font column
		if ( typographyList.length > 0 ) {
			var typoKeys = [];
			typographyList.forEach( function ( item ) {
				if ( typeof item === 'object' && item !== null ) {
					Object.keys( item ).forEach( function ( k ) {
						if ( typoKeys.indexOf( k ) === -1 ) typoKeys.push( k );
					} );
				}
			} );
			if ( typoKeys.length === 0 ) typoKeys = [ '_id', 'id', 'title' ];
			html += '<div class="ai-elementor-sync-kit-typography"><h3 class="ai-elementor-sync-result-heading">Typography (' + typographyList.length + ')</h3>';
			html += '<table class="widefat striped"><thead><tr>';
			typoKeys.forEach( function ( k ) {
				html += '<th>' + escapeHtml( k ) + '</th>';
			} );
			html += '<th>Edit font</th></tr></thead><tbody>';
			typographyList.forEach( function ( item ) {
				var typosId = ( item && ( item._id != null || item.id != null ) ) ? String( item._id != null ? item._id : item.id ) : '';
				var currentFont = ( item && item.typography_font_family ) ? String( item.typography_font_family ) : '';
				html += '<tr data-typo-id="' + escapeHtml( typosId ) + '">';
				if ( typeof item === 'object' && item !== null ) {
					typoKeys.forEach( function ( k ) {
						var v = item[ k ];
						var cell = v === null || v === undefined ? '' : ( typeof v === 'object' ? JSON.stringify( v ) : String( v ) );
						html += '<td>' + ( k === '_id' || k === 'id' ? '<code>' + escapeHtml( cell ) + '</code>' : escapeHtml( cell ) ) + '</td>';
					} );
					var opts = kitFontOptions.map( function ( f ) {
						var sel = ( f === currentFont || ( currentFont === '' && f === 'Default' ) ) ? ' selected' : '';
						return '<option value="' + escapeHtml( f === 'Default' ? '' : f ) + '"' + sel + '>' + escapeHtml( f ) + '</option>';
					} ).join( '' );
					html += '<td><select class="kit-font-select" data-id="' + escapeHtml( typosId ) + '">' + opts + '</select></td>';
				} else {
					html += '<td colspan="' + ( typoKeys.length + 1 ) + '">' + escapeHtml( JSON.stringify( item ) ) + '</td>';
				}
				html += '</tr>';
			} );
			html += '</tbody></table></div>';
		}
		if ( data.raw_settings && typeof data.raw_settings === 'object' ) {
			html += '<details class="ai-elementor-sync-kit-raw"><summary>Raw settings (JSON)</summary><pre>' + escapeHtml( JSON.stringify( data.raw_settings, null, 2 ) ) + '</pre></details>';
		}
		if ( html === '' ) {
			html = '<p>No colors or typography in kit settings.</p>' + ( data ? renderJson( data ) : '' );
		}
		return html;
	}

	function syncKitColorInputs( colorInput ) {
		var row = colorInput.closest && colorInput.closest( 'tr' );
		if ( ! row ) return;
		var hexInput = row.querySelector && row.querySelector( '.kit-hex-input' );
		if ( hexInput && colorInput.value ) hexInput.value = colorInput.value;
	}
	function syncKitHexInput( hexInput ) {
		var val = ( hexInput.value || '' ).trim();
		if ( val.charAt( 0 ) !== '#' ) val = '#' + val;
		if ( /^#[0-9a-fA-F]{6}$/.test( val ) || /^#[0-9a-fA-F]{3}$/.test( val ) ) {
			var colorInput = hexInput.closest && hexInput.closest( 'tr' ) && hexInput.closest( 'tr' ).querySelector( '.kit-color-input' );
			if ( colorInput ) {
				if ( val.length === 4 ) val = '#' + val.charAt( 1 ) + val.charAt( 1 ) + val.charAt( 2 ) + val.charAt( 2 ) + val.charAt( 3 ) + val.charAt( 3 );
				colorInput.value = val;
			}
		}
	}

	function saveDirectEdits() {
		if ( ! lastKitData || ! lastKitData.raw_settings ) {
			setResult( 'result-kit-settings', '<p class="error">Load kit settings first, then edit colors or fonts and click Save direct edits.</p>', true );
			return;
		}
		var resultEl = document.getElementById( 'result-kit-settings' );
		if ( ! resultEl ) return;
		var patch = { colors: [], typography: [] };
		var systemColorsTable = resultEl.querySelector( '.ai-elementor-sync-kit-colors[data-list="system_colors"] tbody' );
		if ( systemColorsTable ) {
			var rows = systemColorsTable.querySelectorAll( 'tr[data-id]' );
			rows.forEach( function ( tr ) {
				var id = tr.getAttribute( 'data-id' );
				var title = tr.getAttribute( 'data-title' ) || '';
				var hexIn = tr.querySelector( '.kit-hex-input' );
				var colorIn = tr.querySelector( '.kit-color-input' );
				var hex = ( hexIn && hexIn.value && hexIn.value.trim() ) ? hexIn.value.trim() : ( colorIn && colorIn.value ? colorIn.value : '' );
				if ( hex.charAt( 0 ) !== '#' ) hex = '#' + hex;
				if ( id && hex ) patch.colors.push( { _id: id, title: title, value: hex } );
			} );
		}
		var typoList = lastKitData.raw_settings.system_typography;
		if ( Array.isArray( typoList ) && typoList.length > 0 ) {
			var fontSelects = resultEl.querySelectorAll( '.kit-font-select' );
			var fontById = {};
			fontSelects.forEach( function ( sel ) {
				var did = sel.getAttribute( 'data-id' );
				if ( did !== null && did !== '' ) fontById[ did ] = sel.value !== undefined ? sel.value : '';
			} );
			typoList.forEach( function ( item ) {
				if ( typeof item !== 'object' || ! item ) return;
				var id = item._id != null ? item._id : ( item.id != null ? item.id : '' );
				if ( ! id ) return;
				var fontVal = fontById[ id ] !== undefined ? fontById[ id ] : ( item.typography_font_family || '' );
				var merged = {};
				for ( var k in item ) if ( Object.prototype.hasOwnProperty.call( item, k ) ) merged[ k ] = item[ k ];
				merged.typography_font_family = fontVal;
				patch.typography.push( merged );
			} );
		}
		if ( patch.colors.length === 0 && patch.typography.length === 0 ) {
			setResult( 'result-kit-settings', '<p class="error">No editable rows found. Load kit settings first.</p>', true );
			return;
		}
		setLoading( 'result-kit-settings' );
		fetch( restUrl + '/kit-settings', {
			method: 'POST',
			headers: getHeaders( true ),
			credentials: 'same-origin',
			body: JSON.stringify( patch ),
		} )
			.then( function ( res ) {
				return responseToJson( res ).then( function ( data ) {
					return { ok: res.ok, status: res.status, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok && result.data ) {
					lastKitData = result.data;
					var content = renderKitSettingsResult( result.data, result.status );
					setResult( 'result-kit-settings', content, false );
				} else {
					var errMsg = ( result.data && result.data.message ) ? result.data.message : 'Request failed (status ' + result.status + ').';
					setResult( 'result-kit-settings', '<p class="error">' + escapeHtml( errMsg ) + '</p>' + ( result.data ? renderJson( result.data ) : '' ), true );
				}
			} )
			.catch( function ( err ) {
				setResult( 'result-kit-settings', '<p class="error">' + escapeHtml( err.message || String( err ) ) + '</p>', true );
			} );
	}

	function initThemeTab() {
		// Direct click on Load button and delegation (in case button is re-rendered or inside form that intercepts)
		var loadBtn = document.getElementById( 'btn-load-kit-settings' );
		if ( loadBtn ) {
			loadBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				loadKitSettings();
			} );
		}
		var saveDirectBtn = document.getElementById( 'btn-save-direct-edits' );
		if ( saveDirectBtn ) {
			saveDirectBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				saveDirectEdits();
			} );
		}
		document.addEventListener( 'input', function ( e ) {
			var t = e.target;
			if ( ! t || ! t.closest || ! t.closest( '#result-kit-settings' ) ) return;
			if ( t.classList && t.classList.contains( 'kit-color-input' ) ) syncKitColorInputs( t );
			if ( t.classList && t.classList.contains( 'kit-hex-input' ) ) syncKitHexInput( t );
		}, true );
		document.addEventListener( 'click', function ( e ) {
			var t = e.target;
			if ( ! t ) return;
			if ( t.id === 'btn-load-kit-settings' || ( t.tagName === 'BUTTON' && t.closest && t.closest( '#tab-theme' ) && t.textContent && t.textContent.indexOf( 'Load kit' ) !== -1 ) ) {
				e.preventDefault();
				e.stopPropagation();
				loadKitSettings();
				return;
			}
			if ( t.id === 'btn-test-kit-settings' || ( t.getAttribute && t.getAttribute( 'data-test-kit' ) ) ) {
				e.preventDefault();
				e.stopPropagation();
				testKitConnection();
			}
		}, true );
		var testBtn = document.getElementById( 'btn-test-kit-settings' );
		if ( testBtn ) testBtn.addEventListener( 'click', function ( e ) { e.preventDefault(); e.stopPropagation(); testKitConnection(); } );
		var form = document.getElementById( 'form-theme' );
		if ( ! form ) return;
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var textarea = document.getElementById( 'theme-patch-json' );
			var raw = textarea ? ( textarea.value || '' ).trim() : '';
			if ( ! raw ) {
				setResult( 'result-kit-settings', '<p class="error">Enter a JSON patch (e.g. {"colors": [...]} or {"typography": [...]}).</p>', true );
				return;
			}
			var patch;
			try {
				patch = JSON.parse( raw );
			} catch ( err ) {
				setResult( 'result-kit-settings', '<p class="error">Invalid JSON: ' + escapeHtml( err.message || String( err ) ) + '</p>', true );
				return;
			}
			if ( typeof patch !== 'object' || patch === null ) {
				setResult( 'result-kit-settings', '<p class="error">Patch must be a JSON object.</p>', true );
				return;
			}
			setLoading( 'result-kit-settings' );
			logToDebug( 'fetch start', { url: restUrl + '/kit-settings', method: 'POST' }, 'C' );
			fetch( restUrl + '/kit-settings', {
				method: 'POST',
				headers: getHeaders( true ),
				credentials: 'same-origin',
				body: JSON.stringify( patch ),
			} )
				.then( function ( res ) {
					return responseToJson( res ).then( function ( data ) {
						return { ok: res.ok, status: res.status, data: data };
					} );
				} )
				.then( function ( result ) {
					if ( result.ok ) {
						var content = renderKitSettingsResult( result.data, result.status );
						setResult( 'result-kit-settings', content, false );
					} else {
						var errMsg = ( result.data && result.data.message ) ? result.data.message : 'Request failed (status ' + result.status + ').';
						setResult( 'result-kit-settings', '<p class="error">' + escapeHtml( errMsg ) + '</p>' + ( result.data ? renderJson( result.data ) : '' ), true );
					}
				} )
				.catch( function ( err ) {
					setResult( 'result-kit-settings', '<p class="error">' + escapeHtml( err.message || String( err ) ) + '</p>', true );
				} );
		} );
	}

	function initSettings() {
		const form = document.getElementById( 'form-settings' );
		if ( ! form ) return;
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			const aiUrl = ( document.getElementById( 'settings-ai-service-url' ) || {} ).value?.trim();
			const llmUrl = ( document.getElementById( 'settings-llm-register-url' ) || {} ).value?.trim();
			const sideloadEl = document.getElementById( 'settings-sideload-images' );
			const sideloadImages = sideloadEl ? sideloadEl.checked : false;
			const resultEl = document.getElementById( 'settings-save-result' );
			if ( resultEl ) resultEl.textContent = 'Saving…';
			logToDebug( 'fetch start', { url: restUrl + '/settings', method: 'POST' }, 'C' );
			fetch( restUrl + '/settings', {
				method: 'POST',
				headers: getHeaders( true ),
				credentials: 'same-origin',
				body: JSON.stringify( { ai_service_url: aiUrl, llm_register_url: llmUrl, sideload_images: sideloadImages } ),
			} )
				.then( function ( res ) {
					return responseToJson( res ).then( function ( data ) {
						return { ok: res.ok, data: data };
					} );
				} )
				.then( function ( result ) {
					if ( resultEl ) {
						resultEl.textContent = result.ok ? 'Saved.' : 'Save failed.';
						resultEl.classList.toggle( 'error', ! result.ok );
					}
				} )
				.catch( function () {
					if ( resultEl ) {
						resultEl.textContent = 'Save failed.';
						resultEl.classList.add( 'error' );
					}
				} );
		} );
	}

	function initLog() {
		const refreshBtn = document.getElementById( 'btn-refresh-log' );
		const clearBtn = document.getElementById( 'btn-clear-log' );
		if ( refreshBtn ) refreshBtn.addEventListener( 'click', loadLog );
		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				logToDebug( 'fetch start', { url: restUrl + '/clear-log' }, 'C' );
				fetch( restUrl + '/clear-log', {
					method: 'POST',
					headers: getHeaders( true ),
					credentials: 'same-origin',
					body: JSON.stringify( {} ),
				} )
					.then( function () { loadLog(); } )
					.catch( function () { loadLog(); } );
			} );
		}
	}

	// Inspect: GET ?url=... or ?template_id=...
	function initInspect() {
		const form = document.getElementById( 'form-inspect' );
		if ( ! form ) return;
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			const payload = getTargetPayload( 'inspect' );
			if ( ! payload ) {
				setResult( 'result-inspect', '<p class="error">Choose Page (URL) and enter a URL, or choose Template and select a template.</p>', true );
				return;
			}
			setLoading( 'result-inspect' );
			const params = new URLSearchParams( payload );
			const reqUrl = restUrl + '/inspect?' + params.toString();
			logToDebug( 'fetch start', { url: reqUrl, restUrl: restUrl }, 'C' );
			fetch( reqUrl, {
				method: 'GET',
				headers: getHeaders( false ),
				credentials: 'same-origin',
			} )
				.then( function ( res ) {
					return responseToJson( res ).then( function ( data ) {
						return { ok: res.ok, status: res.status, data: data };
					} );
				} )
				.then( function ( result ) {
					const content = renderInspectResult( result.data );
					setResult( 'result-inspect', content, ! result.ok );
				} )
				.catch( function ( err ) {
					setResult( 'result-inspect', '<pre>' + ( err.message || String( err ) ) + '</pre>', true );
				} );
		} );
	}

	// Replace text: POST JSON { url or template_id, find, replace }
	function initReplaceText() {
		const form = document.getElementById( 'form-replace-text' );
		if ( ! form ) return;
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			const payload = getTargetPayload( 'replace' );
			const find = ( document.getElementById( 'replace-find' ) || {} ).value;
			const replace = ( document.getElementById( 'replace-replace' ) || {} ).value;
			if ( ! payload || find === undefined || replace === undefined ) {
				setResult( 'result-replace-text', '<p class="error">Choose Page (URL) and enter a URL, or choose Template and select a template. Enter Find and Replace.</p>', true );
				return;
			}
			setLoading( 'result-replace-text' );
			const body = Object.assign( {}, payload, { find: find, replace: replace } );
			logToDebug( 'fetch start', { url: restUrl + '/replace-text' }, 'C' );
			fetch( restUrl + '/replace-text', {
				method: 'POST',
				headers: getHeaders( true ),
				credentials: 'same-origin',
				body: JSON.stringify( body ),
			} )
				.then( function ( res ) {
					return responseToJson( res ).then( function ( data ) {
						return { ok: res.ok, status: res.status, data: data };
					} );
				} )
				.then( function ( result ) {
					setResult( 'result-replace-text', renderJson( result.data ), ! result.ok );
				} )
				.catch( function ( err ) {
					setResult( 'result-replace-text', '<pre>' + ( err.message || String( err ) ) + '</pre>', true );
				} );
		} );
	}

	// LLM edit: POST JSON { url or template_id, instruction }. If target=auto, detect footer/header from instruction. If target=kit, edit global colors/typography.
	function initLlmEdit() {
		const form = document.getElementById( 'form-llm-edit' );
		if ( ! form ) return;
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			const instruction = ( document.getElementById( 'llm-instruction' ) || {} ).value;
			if ( instruction === undefined || ( instruction || '' ).trim() === '' ) {
				setResult( 'result-llm-edit', '<p class="error">Enter an instruction.</p>', true );
				return;
			}
			var llmForm = document.getElementById( 'form-llm-edit' );
			var targetRadio = llmForm ? llmForm.querySelector( 'input[name="llm-target"]:checked' ) : document.querySelector( 'input[name="llm-target"]:checked' );
			var target = targetRadio && targetRadio.value ? String( targetRadio.value ).trim().toLowerCase() : '';
			var payloadPromise;
			if ( target === 'kit' ) {
				setLoading( 'result-llm-edit' );
				payloadPromise = Promise.resolve( { target: 'kit', instruction: instruction } );
			} else if ( target === 'auto' ) {
				var docType = detectDocumentTypeFromInstruction( instruction );
				if ( ! docType ) {
					setResult( 'result-llm-edit', '<p class="error">Auto target: mention "footer", "header", "single", or "page" in your instruction (e.g. "In the footer, change the copyright to 2025").</p>', true );
					return;
				}
				setLoading( 'result-llm-edit' );
				payloadPromise = templatesCache.length > 0
					? Promise.resolve( templatesCache )
					: fetch( restUrl + '/list-templates', { method: 'GET', headers: getHeaders( false ), credentials: 'same-origin' } )
						.then( function ( res ) { return responseToJson( res ); } )
						.then( function ( list ) {
							templatesCache = Array.isArray( list ) ? list : [];
							return templatesCache;
						} );
				payloadPromise = payloadPromise.then( function ( list ) {
					var first = list.filter( function ( t ) { return ( t.document_type || '' ) === docType; } )[ 0 ];
					if ( ! first ) return null;
					return { template_id: first.id, instruction: instruction };
				} );
			} else {
				var manual = getTargetPayload( 'llm' );
				if ( manual && manual.target === 'kit' ) {
					target = 'kit';
					setLoading( 'result-llm-edit' );
					payloadPromise = Promise.resolve( { target: 'kit', instruction: instruction } );
				} else if ( ! manual ) {
					var kitChecked = false;
					var llmFormEl = document.getElementById( 'form-llm-edit' );
					if ( llmFormEl ) {
						var radios = llmFormEl.querySelectorAll( 'input[name="llm-target"]' );
						for ( var r = 0; r < radios.length; r++ ) {
							if ( radios[r].checked && String( radios[r].value ).trim().toLowerCase() === 'kit' ) {
								kitChecked = true;
								target = 'kit';
								setLoading( 'result-llm-edit' );
								payloadPromise = Promise.resolve( { target: 'kit', instruction: instruction } );
								break;
							}
						}
					}
					if ( ! kitChecked ) {
						setResult( 'result-llm-edit', '<p class="error">Choose Page (URL) and enter a URL, or choose Template and select a template.</p>', true );
						return;
					}
				} else {
					setLoading( 'result-llm-edit' );
					payloadPromise = Promise.resolve( Object.assign( {}, manual, { instruction: instruction } ) );
				}
			}
			payloadPromise.then( function ( payload ) {
				if ( ! payload && target === 'auto' ) {
					setResult( 'result-llm-edit', '<p class="error">No template found for that type. Create a ' + docType + ' template in Elementor Theme Builder.</p>', true );
					return;
				}
				var body = target === 'kit' ? { target: 'kit', instruction: instruction } : ( payload && payload.instruction ? payload : Object.assign( {}, payload, { instruction: instruction } ) );
				logToDebug( 'fetch start', { url: restUrl + '/llm-edit', target: target }, 'C' );
				return fetch( restUrl + '/llm-edit', {
					method: 'POST',
					headers: getHeaders( true ),
					credentials: 'same-origin',
					body: JSON.stringify( body ),
				} )
					.then( function ( res ) {
						return responseToJson( res ).then( function ( data ) {
							return { ok: res.ok, status: res.status, data: data };
						} );
					} )
					.then( function ( result ) {
						var content = renderLlmEditResult( result.data, target );
						setResult( 'result-llm-edit', content, ! result.ok );
					} )
					.catch( function ( err ) {
						setResult( 'result-llm-edit', '<pre>' + ( err.message || String( err ) ) + '</pre>', true );
					} );
			} ).catch( function ( err ) {
				setResult( 'result-llm-edit', '<pre>' + ( err.message || String( err ) ) + '</pre>', true );
			} );
		} );
	}

	function renderLlmEditResult( data, target ) {
		if ( ! data ) return renderJson( data );
		// Kit response: status, applied, kit_id, colors?, typography? or status error, message
		if ( data.kit_id != null ) {
			var kitHtml = '';
			if ( data.status === 'ok' ) {
				kitHtml += '<p><strong>Kit updated.</strong> Kit ID: ' + escapeHtml( String( data.kit_id ) ) + '</p>';
				if ( data.applied === false ) {
					kitHtml += '<p class="description">No changes were applied (AI returned an empty patch).</p>';
				}
				if ( ( Array.isArray( data.colors ) && data.colors.length > 0 ) || ( Array.isArray( data.typography ) && data.typography.length > 0 ) ) {
					kitHtml += '<details><summary>Updated colors / typography</summary><pre>' + escapeHtml( JSON.stringify( { colors: data.colors || [], typography: data.typography || [] }, null, 2 ) ) + '</pre></details>';
				}
			} else {
				kitHtml += '<p class="error"><strong>Error:</strong> ' + escapeHtml( data.message || 'Unknown error' ) + '</p>';
			}
			kitHtml += '<div class="ai-elementor-sync-llm-full"><h3 class="ai-elementor-sync-result-heading">Full response</h3>' + renderJson( data ) + '</div>';
			return kitHtml;
		}
		const received = data.received_from_llm;
		let html = '';
		if ( received && typeof received === 'object' ) {
			html += '<div class="ai-elementor-sync-llm-received">';
			html += '<h3 class="ai-elementor-sync-result-heading">Received from LLM</h3>';
			html += '<p>Raw edits: <strong>' + escapeHtml( String( received.raw_edits_count ?? '' ) ) + '</strong>, ';
			html += 'Normalized: <strong>' + escapeHtml( String( received.normalized_edits_count ?? '' ) ) + '</strong></p>';
			if ( Array.isArray( received.response_keys ) && received.response_keys.length > 0 ) {
				html += '<p>Response top-level keys: <code>' + escapeHtml( received.response_keys.join( ', ' ) ) + '</code> ';
				html += '(plugin expects <code>edits</code>, or <code>changes</code>, or <code>results</code>)</p>';
			}
			if ( Array.isArray( received.edits ) && received.edits.length > 0 ) {
				html += '<pre class="ai-elementor-sync-edits-pre">' + escapeHtml( JSON.stringify( received.edits, null, 2 ) ) + '</pre>';
			} else {
				html += '<p>No edits to apply (empty or all dropped by normalization). Check the Log tab for the actual response body.</p>';
			}
			html += '</div>';
		}
		if ( Array.isArray( data.failed ) && data.failed.length > 0 ) {
			html += '<div class="ai-elementor-sync-llm-failed">';
			html += '<h3 class="ai-elementor-sync-result-heading">Failed edits (reason)</h3>';
			html += '<ul>';
			data.failed.forEach( function ( entry ) {
				const reason = entry.reason != null ? escapeHtml( String( entry.reason ) ) : '';
				const idPath = [ entry.id, entry.path ].filter( Boolean ).join( ', ') || '—';
				html += '<li><code>' + escapeHtml( idPath ) + '</code> → <strong>' + reason + '</strong>';
				if ( entry.error ) html += ' (' + escapeHtml( entry.error ) + ')';
				html += '</li>';
			} );
			html += '</ul></div>';
		}
		html += '<div class="ai-elementor-sync-llm-full"><h3 class="ai-elementor-sync-result-heading">Full response</h3>';
		html += renderJson( data ) + '</div>';
		return html;
	}

	// Apply edits: POST JSON { url or template_id, edits }
	function initApplyEdits() {
		const form = document.getElementById( 'form-apply-edits' );
		if ( ! form ) return;
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			const payload = getTargetPayload( 'apply' );
			let editsRaw = ( document.getElementById( 'apply-edits' ) || {} ).value?.trim();
			if ( ! payload ) {
				setResult( 'result-apply-edits', '<p class="error">Choose Page (URL) and enter a URL, or choose Template and select a template.</p>', true );
				return;
			}
			if ( ! editsRaw ) {
				setResult( 'result-apply-edits', '<p class="error">Enter edits (JSON array).</p>', true );
				return;
			}
			let edits;
			try {
				edits = JSON.parse( editsRaw );
			} catch ( err ) {
				setResult( 'result-apply-edits', '<pre>Invalid JSON: ' + ( err.message || String( err ) ) + '</pre>', true );
				return;
			}
			if ( ! Array.isArray( edits ) ) {
				setResult( 'result-apply-edits', '<pre>Edits must be a JSON array.</pre>', true );
				return;
			}
			setLoading( 'result-apply-edits' );
			const body = Object.assign( {}, payload, { edits: edits } );
			logToDebug( 'fetch start', { url: restUrl + '/apply-edits' }, 'C' );
			fetch( restUrl + '/apply-edits', {
				method: 'POST',
				headers: getHeaders( true ),
				credentials: 'same-origin',
				body: JSON.stringify( body ),
			} )
				.then( function ( res ) {
					return responseToJson( res ).then( function ( data ) {
						return { ok: res.ok, status: res.status, data: data };
					} );
				} )
				.then( function ( result ) {
					setResult( 'result-apply-edits', renderJson( result.data ), ! result.ok );
				} )
				.catch( function ( err ) {
					setResult( 'result-apply-edits', '<pre>' + ( err.message || String( err ) ) + '</pre>', true );
				} );
		} );
	}

	// Application password: create button (will be wired in step 3)
	function initAppPassword() {
		const btn = document.getElementById( 'btn-create-app-password' );
		if ( ! btn ) return;
		btn.addEventListener( 'click', function () {
			setLoading( 'app-password-result' );
			logToDebug( 'fetch start', { url: restUrl + '/create-application-password' }, 'C' );
			fetch( restUrl + '/create-application-password', {
				method: 'POST',
				headers: getHeaders( true ),
				credentials: 'same-origin',
				body: JSON.stringify( {} ),
			} )
				.then( function ( res ) {
					return responseToJson( res ).then( function ( data ) {
						return { ok: res.ok, status: res.status, data: data };
					} );
				} )
				.then( function ( result ) {
					if ( result.ok && result.data && result.data.password ) {
						const password = result.data.password;
						const username = result.data.username || '';
						const content =
							'<p><strong>Save this password now. It will not be shown again.</strong></p>' +
							'<div class="password-display">' + escapeHtml( password ) + '</div>' +
							'<p><button type="button" class="button" id="btn-copy-password">Copy</button></p>' +
							'<p class="copy-hint">Use with username <code>' + escapeHtml( username ) + '</code> for Basic auth (e.g. in your LLM app).</p>';
						setResult( 'app-password-result', content, false );
						document.getElementById( 'app-password-result' ).setAttribute( 'data-password', password );
						document.getElementById( 'app-password-result' ).setAttribute( 'data-username', username );
						const registerSection = document.getElementById( 'app-password-register' );
						if ( registerSection ) registerSection.classList.remove( 'hidden' );
						const copyBtn = document.getElementById( 'btn-copy-password' );
						if ( copyBtn ) {
							copyBtn.addEventListener( 'click', function () {
								navigator.clipboard.writeText( password ).then( function () {
									copyBtn.textContent = 'Copied!';
								} );
							} );
						}
					} else {
						const msg = ( result.data && result.data.message ) || ( result.data && result.data.code ) || 'Failed to create application password.';
						setResult( 'app-password-result', '<pre>' + escapeHtml( typeof msg === 'string' ? msg : JSON.stringify( result.data ) ) + '</pre>', true );
						if ( result.status === 503 ) {
							const unavail = document.getElementById( 'app-password-unavailable' );
							const btn = document.getElementById( 'btn-create-app-password' );
							if ( unavail ) unavail.classList.remove( 'hidden' );
							if ( btn ) btn.style.display = 'none';
						}
					}
				} )
				.catch( function ( err ) {
					setResult( 'app-password-result', '<pre>' + escapeHtml( err.message || String( err ) ) + '</pre>', true );
				} );
		} );
	}

	function escapeHtml( str ) {
		if ( str == null ) return '';
		const div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	}

	// Register with LLM app: reads password/username from app-password-result data attributes
	function initRegisterLlm() {
		const regBtn = document.getElementById( 'btn-register-llm' );
		if ( ! regBtn ) return;
		regBtn.addEventListener( 'click', function () {
			const resultEl = document.getElementById( 'app-password-result' );
			const password = resultEl ? resultEl.getAttribute( 'data-password' ) : null;
			const username = resultEl ? resultEl.getAttribute( 'data-username' ) : '';
			if ( ! password ) {
				const out = document.getElementById( 'result-register-llm' );
				if ( out ) {
					out.classList.add( 'error' );
					out.innerHTML = '<p>Create an application password first, then click Register.</p>';
				}
				return;
			}
			const registerUrl = ( document.getElementById( 'llm-register-url' ) || {} ).value?.trim();
			if ( ! registerUrl ) {
				const out = document.getElementById( 'result-register-llm' );
				if ( out ) {
					out.classList.add( 'error' );
					out.innerHTML = '<p>Enter the LLM app register URL.</p>';
				}
				return;
			}
			const out = document.getElementById( 'result-register-llm' );
			if ( out ) {
				out.textContent = 'Sending…';
				out.classList.remove( 'success', 'error' );
			}
			const payload = { site_url: siteUrl, username: username, application_password: password };
			fetch( registerUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( payload ),
			} )
				.then( function ( res ) {
					return res.text().then( function ( text ) {
						return { ok: res.ok, status: res.status, text };
					} );
				} )
				.then( function ( r ) {
					if ( out ) {
						out.classList.add( r.ok ? 'success' : 'error' );
						out.innerHTML = r.ok ? '<pre>Registered successfully.</pre>' : '<pre>' + escapeHtml( r.text || 'Error ' + r.status ) + '</pre>';
					}
				} )
				.catch( function ( err ) {
					if ( out ) {
						out.classList.add( 'error' );
						out.innerHTML = '<pre>' + escapeHtml( err.message || String( err ) ) + '</pre>';
					}
				} );
		} );
	}

	function init() {
		initTabs( function ( tabName ) {
			if ( tabName === 'settings' ) loadSettings();
			if ( tabName === 'log' ) loadLog();
			if ( tabName === 'theme' ) {
				testKitConnection();
				loadKitSettings();
			}
		} );
		loadTemplates();
		initTargetToggles();
		document.querySelectorAll( '.js-retry-templates' ).forEach( function ( el ) {
			el.addEventListener( 'click', function () { loadTemplates(); } );
		} );
		initInspect();
		initReplaceText();
		initLlmEdit();
		initApplyEdits();
		initThemeTab();
		initAppPassword();
		initRegisterLlm();
		initSettings();
		initLog();
		// Pre-fill LLM register URL from option if set
		const llmRegisterInput = document.getElementById( 'llm-register-url' );
		if ( llmRegisterInput && config.llm_register_url ) {
			llmRegisterInput.value = config.llm_register_url;
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
