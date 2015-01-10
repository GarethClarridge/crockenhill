module.exports = function(grunt) {

  //Initializing the configuration object
    grunt.initConfig({

      // Task configuration
    sass: {
        dist: {
            options: {
              style: 'expanded',  
            },
            files: {
              //compiling main.scss into main.css
              "./public/stylesheets/main.css":"./app/assets/stylesheets/main.scss"
            }
        }
    },
    concat: {
      options: {
        separator: ';',
      },
      js_frontend: {
        src: [
          './bower_components/jquery/jquery.js',
          './bower_components/sass-bootstrap/dist/js/bootstrap.js',
          './app/assets/javascript/masonry.pkgd.min.js',
          './app/assets/javascript/frontend.js'
        ],
        dest: './public/scripts/frontend.js',
      },
      js_backend: {
        src: [
          './bower_components/jquery/jquery.js',
          './bower_components/sass-bootstrap/dist/js/bootstrap.js',
          './app/assets/javascript/backend.js'
        ],
        dest: './public/scripts/backend.js',
      },
    },
    uglify: {
      options: {
        mangle: false  // Use if you want the names of your functions and variables unchanged
      },
      frontend: {
        files: {
          './public/scripts/frontend.js': './public/scripts/frontend.js',
        }
      },
      backend: {
        files: {
          './public/scripts/backend.js': './public/scripts/backend.js',
        }
      },
    },
    watch: {
        js_frontend: {
          files: [
            //watched files
            './bower_components/jquery/jquery.js',
            './bower_components/sass-bootstrap/dist/js/bootstrap.js',
            './app/assets/javascript/frontend.js'
            ],   
          tasks: ['concat:js_frontend','uglify:frontend'],     //tasks to run
          options: {
            livereload: true                        //reloads the browser
          }
        },
        js_backend: {
          files: [
            //watched files
            './bower_components/jquery/jquery.js',
            './bower_components/sass-bootstrap/dist/js/bootstrap.js',
            './app/assets/javascript/backend.js'
          ],   
          tasks: ['concat:js_backend','uglify:backend'],     //tasks to run
          options: {
            livereload: true                        //reloads the browser
          }
        },
        sass: {
<<<<<<< HEAD
          files: ['./app/assets/stylesheets/*.scss'],  //watched files
=======
          files: ['./app/assets/stylesheets/cbc/*.scss','./app/assets/stylesheets/variables.scss'],  //watched files
>>>>>>> develop
          tasks: ['sass'],                          //tasks to run
          options: {
            livereload: true                        //reloads the browser
          }
        },
        markup: {
          files: ['./app/**/*.php'],
          options: {
            livereload: true,
          }
        },
      }
    });

  // Plugin loading
  grunt.loadNpmTasks('grunt-contrib-concat');
  grunt.loadNpmTasks('grunt-contrib-watch');
  grunt.loadNpmTasks('grunt-contrib-sass');
  grunt.loadNpmTasks('grunt-contrib-uglify');

  // Task definition
  grunt.registerTask('default', ['watch']);

};
