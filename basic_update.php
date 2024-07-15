<?php

/*
 * Plugin Name: basic_update
 * Plugin URI: https://nullstep.com/wp-plugins
 * Description: theme and plugin updater classes
 * Author: nullstep
 * Author URI: https://nullstep.com
 * Version: 1.0.1
*/

// theme update class

if (!class_exists('WPTU')) {
	class WPTU {
		private $file;
		private $theme;
		private $basename;
		private $active;
		private $username;
		private $repository;
		private $authorize_token;
		private $github_response;

		private $requires;
		private $tested;

		public function __construct($file) {
			$this->file = $file;
			add_action('admin_init', [$this, 'set_theme_properties']);

			return $this;
		}

		public function set_theme_properties() {
			$this->theme = wp_get_theme($this->file);
			$this->basename = basename(dirname($this->file));
			$this->active = ($this->theme->name == _THEME);
		}

		public function set_versions($requires, $tested) {
			$this->requires = $requires;
			$this->tested = $tested;
		}

		public function set_username($username) {
			$this->username = $username;
		}

		public function set_repository($repository) {
			$this->repository = $repository;
		}

		public function authorize($token) {
			$this->authorize_token = $token;
		}

		private function get_repository_info() {
			if (is_null($this->github_response)) {
				$request_uri = sprintf('https://api.github.com/repos/%s/%s/releases', $this->username, $this->repository);

				$curl = curl_init();

				curl_setopt_array($curl, [
					CURLOPT_URL => $request_uri,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_ENCODING => '',
					CURLOPT_MAXREDIRS => 10,
					CURLOPT_TIMEOUT => 0,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
					CURLOPT_CUSTOMREQUEST => 'GET',
					CURLOPT_HTTPHEADER => [
						'Authorization: token ' . $this->authorize_token,
						'User-Agent: WPUpdater/1.0.0'
					]
				]);

				$response = curl_exec($curl);

				curl_close($curl);

				$response = json_decode($response, true);

				if (is_array($response)) {
					$response = current($response);
				}

				$this->github_response = $response;
			}
		}

		public function initialize() {
			add_filter('pre_set_site_transient_update_themes', [$this, 'modify_transient'], 10, 1);
			add_filter('themes_api', [$this, 'theme_popup'], 10, 3);
			add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
		}

		public function modify_transient($transient) {
			if (property_exists($transient, 'checked')) {
				if ($checked = $transient->checked) {
					$this->get_repository_info();

					$out_of_date = version_compare($this->github_response['tag_name'], $checked[$this->basename], 'gt');

					if ($out_of_date) {
						$new_files = $this->github_response['zipball_url'];
						$slug = current(explode('/', $this->basename));

						$theme = [
							'url' => $this->theme['ThemeURI'],
							'slug' => $slug,
							'package' => $new_files,
							'new_version' => $this->github_response['tag_name']
						];

						$transient->response[$this->basename] = (object) $theme;
					}
				}
			}

			return $transient;
		}

		public function theme_popup($result, $action, $args) {
			if ($action !== 'theme_information') {
				return false;
			}

			if (!empty($args->slug)) {
				if ($args->slug == current(explode('/' , $this->basename))) {
					$this->get_repository_info();

					$theme = [
						'name' => $this->theme['Name'],
						'slug' => $this->basename,
						'requires' => $this->$requires ?? '6.3',
						'tested' => $this->$tested ?? '6.4.3',
						'version' => $this->github_response['tag_name'],
						'author' => $this->theme['Author'],
						'author_profile' => $this->theme['AuthorURI'],
						'last_updated' => $this->github_response['published_at'],
						'homepage' => $this->theme['ThemeURI'],
						'short_description' => $this->theme['Description'],
						'sections' => [
							'Description' => $this->theme['Description'],
							'Updates' => $this->github_response['body'],
						],
						'download_link' => $this->github_response['zipball_url']
					];

					return (object) $theme;
				}
			}


			return $result;
		}

		public function after_install($response, $hook_extra, $result) {
			global $wp_filesystem;

			$install_directory = plugin_dir_path($this->file);
			$wp_filesystem->move($result['destination'], $install_directory);
			$result['destination'] = $install_directory;

			if ($this->active) {
				//
			}

			return $result;
		}
	}
}

// eof