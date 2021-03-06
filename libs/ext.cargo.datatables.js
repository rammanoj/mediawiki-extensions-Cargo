$(document).ready(function() {
	$('.cargoDynamicTable').each( function() {
		var params = {};
		var pageLength = $(this).attr( 'data-page-length' );
		if ( pageLength != '' && pageLength > 0 && parseInt( pageLength ) == pageLength ) {
			pageLength = parseInt( pageLength );
			params['pageLength'] = pageLength;
			var lengthOptions = [ 10, 25, 50, 100 ];
			// If this is not one of the default options, add it
			// to the list.
			if ( lengthOptions.indexOf( pageLength ) < 0 ) {
				lengthOptions.push( pageLength );
				lengthOptions.sort( function(a, b){return a-b;} );
				params['lengthMenu'] = lengthOptions;
			}
		}
		$(this).DataTable( params );
	});

} );
