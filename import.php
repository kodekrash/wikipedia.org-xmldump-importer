#!/bin/env php
<?php

/**
 * @copyright 2013,2016 James Linden <kodekrash@gmail.com>
 * @author James Linden <kodekrash@gmail.com>
 * @link https://github.com/kodekrash/wikipedia.org-xmldump-importer
 * @license BSD (2 clause) <http://www.opensource.org/licenses/BSD-2-Clause>
 */

# Set sane extension defaults
ini_set( 'display_errors', true );
ini_set( 'html_errors', false );
error_reporting( E_ALL ^ E_NOTICE );
setlocale( LC_ALL, 'en_US.UTF-8' );
setlocale( LC_TIME, 'UTC' );
setlocale( LC_MONETARY, 'en_US' );
date_default_timezone_set( 'UTC' );

class util {

	public static function usage() {
		echo 'Importer for Wikipedia.org XML Dumps', PHP_EOL, 'Options:', PHP_EOL;
		echo chr(9) . '--driver   Storage driver (mysql, dummy)', PHP_EOL;
		echo chr(9) . '--host     Storage server hostname/ip (default=localhost)', PHP_EOL;
		echo chr(9) . '--port     Storage server port (if not standard)', PHP_EOL;
		echo chr(9) . '--user     Storage server username (if required)', PHP_EOL;
		echo chr(9) . '--pass     Storage server password (if required)', PHP_EOL;
		echo chr(9) . '--name     Database/datastore name', PHP_EOL;
		echo chr(9) . '--file     Wikipedia.org XML dump file', PHP_EOL;
		echo chr(9) . '--schema   Show database schema SQL (MySQL driver only)', PHP_EOL;
		echo chr(9) . '--indexes  Show database index SQL (MySQL driver only)', PHP_EOL;
		echo chr(9) . '--import   Process file, storing page catalog and content', PHP_EOL;
		die( PHP_EOL );
	}

	public static function abort( $msg = null ) {
		die( 'Aborting. ' . ( empty( $msg ) ? null : trim( $msg ) ) . PHP_EOL );
	}

	public static function config() {
		$x = [ 'driver' => null, 'host' => 'localhost', 'port' => null, 'user' => null, 'pass' => null, 'name' => null, 'file' => null, 'schema' => false, 'indexes' => false, 'import' => false ];
		$o = getopt( null, [ 'driver:', 'host:', 'port:', 'user:', 'pass:', 'name:', 'file:', 'schema', 'indexes', 'import' ] );
		if( is_array( $o ) && count( $o ) > 0 ) {
			foreach( $o as $k => $v ) {
				if( array_key_exists( $k, $x ) ) {
					if( in_array( $k, [ 'schema', 'indexes', 'import' ] ) ) {
						$x[ $k ] = true;
					} else if( !empty( $v ) ) {
						$x[ $k ] = $v;
					}
				}
			}
		}
		return $x;
	}

	public static function requirements( $cfg ) {
		if( !in_array( $cfg['driver'], [ 'mysql', 'dummy' ] ) ) {
			self::abort( 'You must specify a data driver (mysql or dummy).' );
		}
		$r = [ 'bzip' => 'bzopen', 'simplexml' => 'simplexml_load_string' ];
		if( $cfg['driver'] == 'mysql' ) {
			$r['mysql'] = 'mysqli_connect';
		}
		foreach( $r as $k => $v ) {
			if( !function_exists( $v ) ) {
				self::abort( $k . ' features not available.' );
			}
		}
		if( $cfg['import'] && !( is_file( $cfg['file'] ) && is_readable( $cfg['file'] ) ) ) {
			self::abort( 'Data file is missing or not readable.' );
		}
	}

	public static function output( $runtime, $speed, $pages, $revisions, $title ) {
		$s = str_pad( $runtime, 12, ' ', STR_PAD_LEFT ) . ' | ';
		$s .= str_pad( $speed, 8, ' ', STR_PAD_LEFT ) . ' /s | ';
		$s .= str_pad( $pages, 12, ' ', STR_PAD_LEFT ) . ' | ';
		$s .= str_pad( $revisions, 12, ' ', STR_PAD_LEFT ) . ' | ';
		$s .= $title . PHP_EOL;
		echo $s;
	}

}

class driver_dummy {

	private $v = false;

	public function add_namespace( $id, $name ) {
		if( $this->v ) {
			echo 'Namespace', PHP_EOL, chr(9), 'id', chr(9), $id, PHP_EOL, chr(9), 'name', chr(9), $name, PHP_EOL;
		}
		return true;
	}

	public function add_contributor( $id, $name ) {
		if( $this->v ) {
			echo 'Contributor', PHP_EOL, chr(9), 'id', chr(9), $id, PHP_EOL, chr(9), 'name', chr(9), $name, PHP_EOL;
		}
		return true;
	}

	public function add_page( $id, $ns, $redirect, $title ) {
		if( $this->v ) {
			echo 'Page', PHP_EOL, chr(9), 'id', chr(9), $id, PHP_EOL, chr(9), 'ns', chr(9), $ns, PHP_EOL;
			echo chr(9), 'redirect', chr(9), $redirect, PHP_EOL, chr(9), 'title', chr(9), $redirect, PHP_EOL;
		}
		return true;
	}

	public function add_revision( $id, $page, $contrib, $parent, $dt, $length, $minor, $comment, $sha1, $body ) {
		if( $this->v ) {
			echo 'Revision', PHP_EOL, chr(9), 'id', chr(9), $id, PHP_EOL, chr(9), 'page', chr(9), $page, PHP_EOL;
			echo chr(9), 'contrib', chr(9), $contrib, PHP_EOL, chr(9), 'parent', chr(9), $parent, PHP_EOL;
			echo chr(9), 'datetime', chr(9), $dt, PHP_EOL, chr(9), 'length', chr(9), $length, PHP_EOL;
			echo chr(9), 'minor', chr(9), $minor, PHP_EOL, chr(9), 'comment', chr(9), $comment, PHP_EOL;
			echo chr(9), 'sha1', chr(9), $sha1, PHP_EOL, chr(9), 'body', chr(9), str_replace( [ chr(10), chr(13) ], ' ', substr( $body, 0, 100 ) ), PHP_EOL;
		}
		return true;
	}

}

class driver_mysql {

	private $c = null;

	public function __construct( $cfg ) {
		$this->c = new mysqli( $cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name'], $cfg['port'] );
		if( $this->c->connect_error || $this->c->error ) {
			util::abort( 'Unable to connect to database.' );
		}
	}

	private function q( $s ) {
		return "'" . $this->c->real_escape_string( (string)$s ) . "'";
	}

	public function add_namespace( $id, $name ) {
		$s = 'INSERT INTO namespace (id,name) VALUES (%s,%s)';
		if( $this->c->query( sprintf( $s, $this->q( $id ), $this->q( $name ) ) ) ) {
			return true;
		} else {
			util::abort( 'mysql error (' . $this->c->error . ').' );
		}
	}

	public function add_contributor( $id, $name ) {
		$s = 'SELECT COUNT(*) FROM contrib WHERE id=%d';
		if( $q = $this->c->query( sprintf( $s, (int)$id ) ) ) {
			if( $r = $q->fetch_array() ) {
				if( $r[0] == 0 ) {
					$s = 'INSERT INTO contrib (id,name) VALUES (%d,%s)';
					if( $this->c->query( sprintf( $s, (int)$id, $this->q( $name ) ) ) ) {
						return true;
					} else {
						util::abort( 'mysql error (' . $this->c->error . ').' );
					}
				} else {
					return true;
				}
			} else {
				util::abort( 'mysql error (' . $this->c->error . ').' );
			}
		} else {
			util::abort( 'mysql error (' . $this->c->error . ').' );
		}
	}

	public function add_page( $id, $ns, $redirect, $title ) {
		$s = 'INSERT INTO page (id,namespace,redirect,title,search) VALUES (%d,%s,%s,%s,%s)';
		if( $this->c->query( sprintf( $s, $id, $this->q( $ns ), empty( $redirect ) ? 'NULL' : $this->q( $redirect ), $this->q( $title ), $this->q( strtolower( $title ) ) ) ) ) {
			return true;
		} else {
			util::abort( 'mysql error (' . $this->c->error . ').' );
		}
	}

	public function add_revision( $id, $page, $contrib, $parent, $dt, $length, $minor, $comment, $sha1, $body ) {
		$s = 'INSERT INTO revision (id,page,contrib,parent,datetime,length,minor,comment,sha1,body) VALUES (%d,%d,%d,%d,%s,%d,%s,%s,%s,%s)';
		$p = $this->q( $parent );
		$d = $this->q( $dt );
		$m = $this->q( $minor );
		$c = $this->q( $comment );
		$h = $this->q( $sha1 );
		$b = $this->q( $body );
		if( $this->c->query( sprintf( $s, $id, $page, $contrib, $p, $d, $length, $m, $c, $h, $b ) ) ) {
			return true;
		} else {
			util::abort( 'mysql error (' . $this->c->error . ').' );
		}
	}

	public static function schema() {
		$t = [
			'namespace' => "id INT(3) SIGNED PRIMARY KEY, name VARCHAR(64) DEFAULT NULL",
			'contrib' => "id BIGINT UNSIGNED PRIMARY KEY, name VARCHAR(64) DEFAULT NULL",
			'page' => "id BIGINT UNSIGNED PRIMARY KEY, namespace INT(3) SIGNED DEFAULT NULL, search VARCHAR(255) DEFAULT NULL, redirect VARCHAR(255) DEFAULT NULL, title VARCHAR(255) DEFAULT NULL",
			'revision' => "id BIGINT UNSIGNED PRIMARY KEY, page BIGINT UNSIGNED DEFAULT NULL, contrib BIGINT UNSIGNED DEFAULT NULL, parent BIGINT UNSIGNED DEFAULT NULL, datetime DATETIME DEFAULT NULL, length INT DEFAULT NULL, minor BOOLEAN DEFAULT NULL, comment VARCHAR(255) DEFAULT NULL, sha1 VARCHAR(40) DEFAULT NULL, body LONGTEXT",
		];
		$s = '';
		foreach( $t as $k => $v ) {
			$s .= 'CREATE TABLE IF NOT EXISTS ' . $k . ' (' . PHP_EOL . chr(9);
			$s .= str_replace( ', ', ',' . PHP_EOL . chr(9), $v ) . PHP_EOL . ') ENGINE=MyISAM;' . PHP_EOL . PHP_EOL;
		}
		return $s;
	}

	public static function indexes() {
		$s = 'CREATE INDEX i_namespace ON page (namespace);' . PHP_EOL . 'CREATE INDEX i_page ON revision (page);' . PHP_EOL;
		$s .= 'CREATE INDEX i_contrib ON revision (contrib);' . PHP_EOL . 'CREATE FULLTEXT INDEX i_search ON page (search);' . PHP_EOL;
		return $s;
	}

}


class reader {

	private $c = null;
	private $d = null;
	private $t = null;
	private $cp = 0;
	private $cr = 0;

	public function __construct( $cfg ) {
		if( is_array( $cfg ) ) {
			$this->c = $cfg;
		}
	}

	private function driver() {
		switch( $this->c['driver'] ) {
			case 'mysql':
				$this->d = new driver_mysql( $this->c );
			break;
			case 'dummy':
				$this->d = new driver_dummy( $this->c );
			break;
		}
		if( empty( $this->d ) ) {
			util::abort( 'Unable to initialize storage driver.' );
		}
	}

	private function runtime() {
		$h = $m = $s = 0;
		$s = microtime( true ) - $this->t;
		if( $s > 60 ) {
			$m = $s / 60;
			$s = $s % 60;
		}
		if( $m > 60 ) {
			$h = $m / 60;
			$m = $m % 60;
		}
		return str_pad( (int)$h, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( (int)$m, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( (int)$s, 2, '0', STR_PAD_LEFT );
	}

	private function speed() {
		return (int)( $this->cp / ( microtime( true ) - $this->t ) );
	}

	public function process() {
		$start = false;
		$chunk = $line = null;
		$this->driver();
		$in = bzopen( $this->c['file'], 'r' );
		if( !$in ) {
			util::abort( 'Unable to open input file.' );
		}
		$this->t = microtime( true );
		while( !feof( $in ) ) {
			$l = bzread( $in, 1 );
			if( $l === false ) {
				util::abort( 'Error reading compressed file.' );
			}
			if( $l == PHP_EOL ) {
				$line = trim( $line );
				if( $line == '<namespaces>' || $line == '<page>' ) {
					$start = true;
				}
				if( $start === true ) {
					$chunk .= $line . PHP_EOL;
				}
				if( $line == '</namespaces>' ) {
					$start = false;
					$chunk = str_replace( [ '">', '</namespace>' ], [ '" name="', '" />' ], $chunk );
					$x = @simplexml_load_string( $chunk );
					$chunk = null;
					if( $x ) {
						foreach( $x->namespace as $y ) {
							$y = (array)$y;
							$ni = (int)$y['@attributes']['key'];
							$nn = array_key_exists( 'name', $y['@attributes'] ) ? (string)$y['@attributes']['name'] : null;
							if( $this->c['import'] ) {
								$this->d->add_namespace( $ni, $nn );
							}
						}
					} else {
						util::abort( 'Unable to parse namespaces.' );
					}
				} else if( $line == '</page>' ) {
					$start = false;
					$x = @simplexml_load_string( $chunk );
					$chunk = $line = null;
					if( $x ) {
						$pi = (string)$x->id;
						$pt = (string)$x->title;
						$pn = (string)$x->ns;
						$pr = null;
						if( $x->redirect ) {
							$y = (array)$x->redirect;
							$pr = $y['@attributes']['title'];
						}
						$z = false;
						if( $this->c['import'] ) {
							$z = $this->d->add_page( $pi, $pn, $pr, $pt );
						}
						if( $z ) {
							$this->cp ++;
							if( $x->revision ) {
								$ci = 0;
								if( $x->revision->contributor ) {
									$ci = (string)$x->revision->contributor->id;
									$cu = (string)$x->revision->contributor->username;
									if( $this->c['import'] ) {
										$this->d->add_contributor( $ci, $cu );
									}
								}
								$ri = (string)$x->revision->id;
								$rp = (string)$x->revision->parentid;
								$rd = gmdate( 'Y-m-d H:i:s', strtotime( (string)$x->revision->timestamp ) );
								$rm = $x->revision->minor ? true : false;
								$rc = (string)$x->revision->comment;
								$rs = (string)$x->revision->sha1;
								$rt = (string)$x->revision->text;
								$rl = strlen( $rt );
								$z = false;
								if( $this->c['import'] ) {
									$z = $this->d->add_revision( $ri, $pi, $ci, $rp, $rd, $rl, $rm, $rc, $rs, $rt );
								}
								if( $z ) {
									$this->cr ++;
								}
							}
							util::output( $this->runtime(), $this->speed(), $this->cp, $this->cr, $pt );
						}
					}
				}
				$line = null;
			} else {
				$line .= $l;
			}
		}
	}

}

/**
 * Abort if not running CLI
 */
if( PHP_SAPI != 'cli' ) {
	util::abort( 'Refusing to run outside command line.' );
}

/**
 * @var $cfg
 */
$cfg = util::config();
if( empty( $cfg['driver'] ) ) {
	util::usage();
}

/**
 * Check env and input params
 */
util::requirements( $cfg );

if( $cfg['driver'] == 'mysql' ) {
	if( $cfg['schema'] ) {
		echo driver_mysql::schema();
	}
	if( $cfg['indexes'] ) {
		echo driver_mysql::indexes();
	}
}

if( $cfg['import'] ) {
	$x = new reader( $cfg );
	$x->process();
}

?>