module.exports = {
	livereload: {
		files: ['assets/css/*.css'],
		options: {
			livereload: true
		}
	},
	css: {
		files: ['assets/css/*.css', '!assets/css/*.min.css'],
		tasks: ['css'],
		options: {
			debounceDelay: 500
		}
	},
	js: {
		files: ['assets/js/src/**/*.js', 'assets/js/vendor/**/*.js'],
			tasks: ['js'],
			options: {
			debounceDelay: 500
		}
	}
};
