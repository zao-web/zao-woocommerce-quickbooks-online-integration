module.exports = {
	main: {
		options: {
			mode: 'zip',
			archive: './release/zwqoi.<%= pkg.version %>.zip'
		},
		expand: true,
		cwd: 'release/<%= pkg.version %>/',
		src: ['**/*'],
		dest: 'zwqoi/'
	}
};