<?php
/*
 *
 * XenForo 1.2 to SMF 2.0.x converter
 * author: margarett (Bruno Alves) for Simple Machines Forum
 *
 * license: This work is licensed under a Creative Commons Attribution 3.0 Unported License.
	http://creativecommons.org/licenses/by/3.0/
 *
 * PLEASE NOTE: This is a converter from XenForo 1.2 to SMF 2.0.x. It was created using the database schema from this specific XF version.
	Please keep in mind that I do *NOT* run any XF forum and, as such, it is possible that something is not exactly the same between your
	version and what I've used. Just ask!
 * Feel free to redistribute, modify, adapt, improve, whatever you wish. Just respect the (very) permissive license and keep this small
	comment block.
 * 
*/
error_reporting(E_ALL ^ E_DEPRECATED);

// Include the SSI file.
require(dirname(__FILE__) . '/SSI.php');
global $boarddir, $db_prefix, $db_name, $boardurl, $xf_dir, $smf_dir, $smcFunc;

require ($boarddir . '/Sources/DbPackages-mysql.php');
db_packages_init(); //Extra operations in smcFunc
//require ($boarddir . '/Sources/DbExtra-mysql.php');
//db_extra_init(); //Extra operations in smcFunc
error_reporting(E_ALL & ~E_DEPRECATED);
if (isset($_REQUEST['step']))
	$step = (int)$_REQUEST['step'];
else
	$step = 0;
//echo 'step: ' . $step;	
header('Content-Type: text/html; charset=utf-8');
while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);
set_time_limit(0);

$prefix_smf = $db_prefix;
//echo $prefix_smf;
//In my short tests, XF doesn't allow you to change your table prefix. If yours isnt't "xf_", by all means change the end of this line here:
$prefix_xf = '`' . $db_name . '`.xf_';
//echo '<br>';
//echo $prefix_xf;

//As stated, this is NOT a "for dummies" converter, despite being develped by one :)

//YOU HAVE TO DEFINE THIS
$xf_dir = '';
$max_queries = 50;	//How many queries are we doing at once? There will be a pause after this
					//a big number will hammer your server, a small number might take ages to finish :)
					
$automated = false;	//Change to true if you wish the converter to move steps automatically

//THIS IS WHERE YOUR DEFINITIONS END

//Base path of this script. Will be very handy
$script = $_SERVER['SCRIPT_NAME'];
$smf_dir = $boarddir;
//From now on, we enter a state machine kind of thing in each step represents a kind of operation
switch ($step)
{
	//The initial, default step
	case 0:
			//Let's salute people :)
			echo '<h2>Hi there. This converter will convert (who\'d tell?) your Xenforo 1.2 to SMF 2.0.x </h2><br>
				 <h3>Before we start, though, there are some things we need to be sure are ready to work...</h3> Let\'s list them here:<br>
				 - Your SMF installation is ready? Can you connect to it and see your "admin" user and the sample Category/Board/Topic?<br>
				 - Did you install SMF in the SAME database where XenForo was installed?<br>
				 - You MUST chmod 755 "avatars" and "attachments" folder. We need to move files there, right?<br>
				 - We need to know SMF and XF\'s folder if we are going to convert attachments, avatars, etc... PLEASE MAKE SURE THIS IS CORRECT! Some help:
					<ul><li>SMF\'s folder: ' . $smf_dir; '</li>';
					if (!empty($xf_dir))
						$xf_msg = $xf_dir;
					else
						$xf_msg = '<span style="color:red;font-weight:bold">You didn\'t set this. Please open the file and do that!</span>';
					
			echo	'<li>XF\'s folder: ' . $xf_msg . '</li></ul>
				 - <span style="color:red;font-weight:bold">DID YOU BACKUP? Again: DID YOU BACKUP?!</span><br>';
				unset ($xf_msg); //cleanup
				 //Now we need to run a quick test to see if the tables are least there...
				echo '<br>We will now run a quick test to the database. If it fails, you will see a "Database Error". Not good, hey?<br>';
				
				$name = $prefix_smf . 'members';
				$temp = $smcFunc['db_table_structure'] ($name);
				echo 'Table "members from SMF is here ;) <br>';
				$name = $prefix_xf . 'user';
				$temp = $smcFunc['db_table_structure'] ($name);
				echo 'Table "user" from XenForo is also here ;) <br>
					  Everything should be fine. Shall we start?<br>';
				unset ($name);
				unset ($temp);
					  
				echo '<h2><a href="' . $script . '?step=1">Please click here to proceed!</a></h2>';				
		break;
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////		
	case 1:
			echo '<h2>This is the first step of the conversion. We are trying to convert Members</h2><br>
				First, let\'s trash the actual contents of SMF members table....<br>';
			$result = $smcFunc['db_query']('', '
					TRUNCATE TABLE ' . $prefix_smf . 'members'
			);
			echo 'Members cleared from SMF table. Next step, how many members are in XF "users" table? <span style="font-weight:bold">';
			$result = $smcFunc['db_query']('', '
					SELECT COUNT(m1.user_id) as total
					FROM ' . $prefix_xf . 'user AS m1
					WHERE m1.user_id != 0'
			);
			$data = $smcFunc['db_fetch_assoc'] ($result);
			$num_members = $data['total'];
			unset ($data);
			$smcFunc['db_free_result'] ($result);
			echo $num_members . ' users were found in XF.</span><br>';
			
			//Now we will copy the members from one table to another. Since it can be intensive to the server, we will do it in small steps..
			$loop_counter = 0;
			$counter = 0;
			$num_loops = (int)($num_members/$max_queries); //complete number of loops
			$done = false;
			$members = array();
			//a div with overflow so that the page won't scroll forever...
			echo '<div style="width:100%;height:300px;overflow:scroll;">';
			while (!$done)
			{
				$lower_limit = ($loop_counter * $max_queries); //This is the starting row
				if ($num_loops > $loop_counter) //is this still a complete set of queries?
					$upper_limit = $max_queries; //number of rows to return
				else
					$upper_limit = $num_members - ($num_loops * $max_queries); //last trip
					
				echo 'Now retrieving members ' . ($lower_limit + 1) . ' to ' . ($lower_limit + $upper_limit) . '...<br>';	
				//This is the mega-ultra-huge query to retrieve members data...
				$query = '
					SELECT
						u.user_id AS id_member,
						u.username AS member_name,
						u.register_date AS date_registered,
						u.message_count AS posts,
						u.is_admin AS is_admin,
						CASE
							WHEN u.is_admin = 1 THEN 1
							WHEN u.user_group_id = 4 THEN 2
							ELSE 0
						END AS id_group,
						u.last_activity AS last_login,
						uau.data AS passwd,
						u.email AS email_address,
						CASE
							WHEN u.gender = \'male\' THEN 1
							WHEN u.gender = \'female\' THEN 2
							ELSE 0
						END AS gender,
						IF (u.user_state = \'valid\', 1, 0) AS is_activated,
						CONCAT(up.dob_year,\'-\',up.dob_month, \'-\', up.dob_day) AS birthdate,
						up.homepage AS homepage,
						up.location AS location,
						\'\' AS icq, \'\' AS aim, \'\' AS yim, \'\' AS msn,
						u.visible AS show_online,
						up.signature AS signature,
						u.custom_title AS usertitle
												
					FROM ' . $prefix_xf . 'user AS u
						INNER JOIN ' . $prefix_xf . 'user_profile AS up ON (u.user_id = up.user_id)
						INNER JOIN ' . $prefix_xf . 'user_authenticate AS uau ON (uau.user_id = up.user_id)
					WHERE u.user_id != 0
					ORDER BY u.user_id ASC
					LIMIT ' . $lower_limit . ', ' . $upper_limit;
//				echo '<pre>' . $query . '</pre>';
				
				$request = $smcFunc['db_query']('', $query
				);
//
				while ($row = $smcFunc['db_fetch_assoc']($request))
				{
					$members[] = $row;
				}
				// echo '<pre>';
				// print_r ($members);
				// echo '</pre>';
				
				$smcFunc['db_free_result']($request);
				// foreach ($members as $temp)
				// {
					// echo '<pre>';
					// print_r ($temp);
					// echo '</pre>';
				// }
				// unset ($temp);

				//For each "batch" that we gather we need to dump it to the new table. Shall we?
				//let's build a nice data array
				$data_array = array();
				foreach ($members as $key => $value)
				{
					$temp_passwd = unserialize($value['passwd']);
					if (empty($temp_passwd['hash']))
						$temp_passwd['hash'] = generateRandomString();
					$data_array[] = array(
										'id_member' => $value['id_member'],
										'member_name' => $value['member_name'],
										'date_registered' => $value['date_registered'],
										'posts' => $value['posts'],
										'id_group' => $value['id_group'],
										'lngfile' => '',
										'last_login' => $value['last_login'],
										'real_name' => $value['member_name'],
										'instant_messages' => 0,
										'unread_messages' => 0,
										'new_pm' => 0,
										'buddy_list' => '',
										'pm_ignore_list' => '',
										'pm_prefs' => 0,
										'mod_prefs' => '',
										'message_labels' => '',
										'passwd' => $temp_passwd['hash'],
										'openid_uri' => '',
										'email_address' => $value['email_address'],
										'personal_text' => '',
										'gender' => $value['gender'],
										'birthdate' => $value['birthdate'],
										'website_title' => $value['homepage'],
										'website_url' => $value['homepage'],
										'location' => $value['location'],
										'icq' => '',
										'aim' => '',
										'yim' => '',
										'msn' => '',
										'hide_email' => 1,
										'show_online' => $value['show_online'],
										'time_format' => '',
										'signature' => $value['signature'],
										'time_offset' => 0,
										'avatar' => '',
										'pm_email_notify' => 0,
										'karma_bad' => 0,
										'karma_good' => 0,
										'usertitle' => $value['usertitle'],
										'notify_announcements' => 1,
										'notify_regularity' => 1,
										'notify_send_body' => 0,
										'notify_types' => 2,
										'member_ip' => '',
										'member_ip2' => '',
										'secret_question' => '',
										'secret_answer' => '',
										'id_theme' => 0,
										'is_activated' => $value['is_activated'],
										'validation_code' => '',
										'id_msg_last_visit' => 0,
										'additional_groups' => '',
										'smiley_set' => '',
										'id_post_group' => 0,
										'total_time_logged_in' => 0,
										'password_salt' => '',
										'ignore_boards' => '',
										'warning' => 0,
										'passwd_flood' => '',
										'pm_receive_from' => 1,
									);
				}
				// echo '<pre>';
				// print_r ($data_array);
				// echo '</pre>';
				
				$smcFunc['db_insert']('insert',
					$prefix_smf . 'members',
					array('id_member' => 'int',
							'member_name' => 'string',
							'date_registered' => 'int',
							'posts' => 'int',
							'id_group' => 'int',
							'lngfile' => 'string',
							'last_login' => 'int',
							'real_name' => 'string',
							'instant_messages' => 'int',
							'unread_messages' => 'int',
							'new_pm' => 'int',
							'buddy_list' => 'string',
							'pm_ignore_list' => 'string',
							'pm_prefs' => 'int',
							'mod_prefs' => 'string',
							'message_labels' => 'string',
							'passwd' => 'string',
							'openid_uri' => 'string',
							'email_address' => 'string',
							'personal_text' => 'string',
							'gender' => 'int',
							'birthdate' => 'string',
							'website_title' => 'string',
							'website_url' => 'string',
							'location' => 'string',
							'icq' => 'string',
							'aim' => 'string',
							'yim' => 'string',
							'msn' => 'string',
							'hide_email' => 'int',
							'show_online' => 'int',
							'time_format' => 'string',
							'signature' => 'string',
							'time_offset' => 'int',
							'avatar' => 'string',
							'pm_email_notify' => 'int',
							'karma_bad' => 'int',
							'karma_good' => 'int',
							'usertitle' => 'string',
							'notify_announcements' => 'int',
							'notify_regularity' => 'int',
							'notify_send_body' => 'int',
							'notify_types' => 'int',
							'member_ip' => 'string',
							'member_ip2' => 'string',
							'secret_question' => 'string',
							'secret_answer' => 'string',
							'id_theme' => 'int',
							'is_activated' => 'int',
							'validation_code' => 'string',
							'id_msg_last_visit' => 'int',
							'additional_groups' => 'string',
							'smiley_set' => 'string',
							'id_post_group' => 'int',
							'total_time_logged_in' => 'int',
							'password_salt' => 'string',
							'ignore_boards' => 'string',
							'warning' => 'int',
							'passwd_flood' => 'string',
							'pm_receive_from' => 'int',
						),
					$data_array,
					array('id_member')
				);
				
				//flush you!
				unset($data_array);
				unset($members);
				
				$loop_counter++; //There ya go, now increase the loop counter
				$counter += $upper_limit; //increase the elements counter. Don't forget we added "1" to lower limit!
				
				//Did we finish or WHAT?!
				if ($counter >= $num_members)
					$done = true;
				else
					sleep(1);				
			}
			//Well, we've finished. YAY :) Let's just say so. Let's move ahead, shall we?
			echo '</div><h2>The script finished moving ' . $counter . ' members from XF to SMF.<br>
			<a href="' . $script . '?step=2">Please click here to proceed!</a><br>';
			if ($automated)
			{
				echo ' Or wait 5 seconds to proceed automatically';
				sleep(5);
				echo '<script>window.location = \'' . $script . '?step=2\'</script></h2>';
				die();
			}
			echo '</h2>';
		break;
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	case 2:
			echo '<h2>This is the second step of the conversion. We are trying to convert Categories</h2><br>
				First, let\'s trash the actual contents of SMF categories table....<br>';
			$result = $smcFunc['db_query']('', '
					TRUNCATE TABLE ' . $prefix_smf . 'categories'
			);
			echo 'Categories cleared from SMF table. Next step, how many categories are in XF "node" table and whose category id is "Category"? <span style="font-weight:bold">';
			$query = '
					SELECT COUNT(m1.node_id) as total
					FROM ' . $prefix_xf . 'node AS m1
					WHERE m1.node_type_id = \'Category\'';			
			//echo '<pre>' . $query . '</pre>';			
			$result = $smcFunc['db_query']('', $query
			);
			$data = $smcFunc['db_fetch_assoc'] ($result);
			$num_categs = $data['total'];
			unset ($data);
			$smcFunc['db_free_result'] ($result);
			echo $num_categs . ' categories were found in XF.</span><br>';
			//Now we will get their datas and send them to SMF' table. Small amount of data, so we'll do it at once!
			$categs = array();
			//Simple query for getting the necessary data for SMF
			$query = '
					SELECT
						c.node_id AS id_cat,
						c.title AS name
					FROM ' . $prefix_xf . 'node AS c
					WHERE c.node_type_id = \'Category\'';
			//echo '<pre>' . $query . '</pre>';
			
			$request = $smcFunc['db_query']('', $query
			);

			while ($row = $smcFunc['db_fetch_assoc']($request))
				$categs[] = $row;
			// echo '<pre>';
			// print_r ($categs);
			// echo '</pre>';				
			$smcFunc['db_free_result']($request);

			//let's build a nice data array
			$data_array = array();
			$k = 0;
			foreach ($categs as $key => $value)
			{
				$data_array[] = array(
									'id_cat' => $value['id_cat'],
									'cat_order' => $k,
									'name' => $value['name'],
									'can_collapse' => 1,
								);
				$k++;				
			}
			unset ($k);
			// echo '<pre>';
			// print_r ($data_array);
			// echo '</pre>';
			
			$smcFunc['db_insert']('insert',
				$prefix_smf . 'categories',
				array('id_cat' => 'int',
						'cat_order' => 'int',
						'name' => 'string',
						'can_collapse' => 'int',
					),
				$data_array,
				array('id_cat')
			);
			
			//flush you!
			unset($data_array);
			unset($categs);	

			//This one was easy. Let's move on to boards!
			echo '</div><h2>The script finished moving ' . $num_categs . ' categories from XF to SMF.<br>
			<a href="' . $script . '?step=3">Please click here to proceed!</a>';
			if ($automated)
			{
				echo ' Or wait 5 seconds to proceed automatically';
				sleep(5);
				echo '<script>window.location = \'' . $script . '?step=3\'</script></h2>';
				die();
			}
			echo '</h2>';			
		break;	
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	case 3:
			echo '<h2>This is the third step of the conversion. We are trying to convert Boards</h2><br>
				First, let\'s trash the actual contents of SMF "boards" table....<br>';
			$result = $smcFunc['db_query']('', '
					TRUNCATE TABLE ' . $prefix_smf . 'boards'
			);
			echo 'Boards cleared from SMF table. Next step, how many boards are in XF "node" table and whose category id is "Forum"/"ForumLink"? <span style="font-weight:bold">';
			//We need every single "node" of XF in order to establish correct parents. So, lets just use one query and split things after, right? ;)
			$query = '
					SELECT c.node_id as id,
						c.node_type_id as type
					FROM ' . $prefix_xf . 'node AS c
					ORDER BY c.node_id';
			//echo '<pre>' . $query . '</pre>';
			$nodes = array();
			$result = $smcFunc['db_query']('', $query
			);
			while ($row = $smcFunc['db_fetch_assoc']($result))
				$nodes[] = $row;			
			$smcFunc['db_free_result'] ($result);
			//Now, lets build sub-arrays for each type. And we count them in the process ;)
			$num_boards = 0;
			$num_links = 0;
			$id_categs = array();
			$id_boards = array();
			foreach ($nodes as $temp)
			{
				// echo '<pre>';
				// print_r ($temp);
				// echo '</pre>';
				if ($temp['type'] == 'Category') //This is a category
					$id_categs[] = $temp['id'];
				elseif ($temp['type'] == 'Forum') //This is a board
				{
					$id_boards[] = $temp['id'];
					$num_boards++;
				}
				elseif ($temp['type'] == 'LinkForum') //This is a redirect board
					$num_links++;
			}
			// echo '<pre>';
			// print_r ($id_categs);
			// print_r ($id_boards);
			// echo '</pre>';
			
			//Now lets tell what we have, right?
			echo $num_boards . ' boards and ' . $num_links . ' "redirect boards" were found in XF.</span><br>';

			//Lets fetch the boards!
			$query = '
					SELECT
						b.node_id AS id_board,
						b.title AS name,
						b.parent_node_id as id_parent,
						b.description as description,
						b.depth as depth
					FROM ' . $prefix_xf . 'node AS b
					WHERE (b.node_type_id = \'Forum\'
						OR b.node_type_id = \'LinkForum\')';
			//echo '<pre>' . $query . '</pre>';
			$request = $smcFunc['db_query']('', $query
			);
			while ($row = $smcFunc['db_fetch_assoc']($request))
				$boards[] = $row;
			$smcFunc['db_free_result']($request);
			// echo '<pre>';
			// print_r ($boards);
			// echo '</pre>';
			//If we have some redirect boards we must also get the "link_forum" table, because the links and the redirect count are there...
			if ($num_links > 0)
			{
				$links = array();
				$query = '
						SELECT *
						FROM ' . $prefix_xf . 'link_forum AS lf
						ORDER BY lf.node_id';
				//echo '<pre>' . $query . '</pre>';
				$request = $smcFunc['db_query']('', $query
				);
				while ($row = $smcFunc['db_fetch_assoc']($request))
					$links[] = $row;
				$smcFunc['db_free_result']($request);
				// echo '<pre>';
				// print_r ($links);
				// echo '</pre>';
				//Lets have ourselves a easier array to search
				$id_links = array();
				foreach ($links as $key_link)
					$id_links[] = $key_link['node_id'];
				// echo '<pre>';
				// print_r ($id_links);
				// echo '</pre>';	
			}
			//Now, let's build the array we will want to submit to SMF!
			$data_array = array();
			//We need to spend extra memory to run the "boards" array twice :(
			$boards_temp = $boards;
			$k = 1;
			foreach ($boards as $value)
			{
				//Before we write stuff to the array, some datas need "work"
				// --> parent_id, home category and child board
				if ($value['id_parent'] == 0) //no parent? we can't have that, sorry...
				{
					$parent_categ = $id_categs[0]; //first category by default...
					$parent_board = 0;
					$child_level = 0;
				}	
				else
				{
					//Now, is our parent a category or a forum?!
					$key = array_search($value['id_parent'], $id_categs); //Search it in categories
					if ($key !== FALSE) //Yes, a category was found
					{
						$parent_categ = $id_categs[$key];
						$parent_board = 0;
						$child_level = 0;
					}	
					else	
					{
						//If it's not a category, then is a forum :)
						$key = array_search($value['id_parent'], $id_boards);
						$parent_board = $id_boards[$key];
						//We still need a category for this... Bum!
						$parent_categ = $id_categs[0]; //In case this fails (Shouldn't fail!!!)
						foreach ($boards_temp as $temp)
						{
							if ($temp['id_board'] == $parent_board) //Found our "parent" board?
							{
								$parent_categ = $temp['id_parent'];
								break; //don't run foreach anymore :)							
							}
						}
						$child_level = 1;
					}
				}
				//And we need to search for redirects in the currect board being analysed. Do they even exist?
				if (isset($id_links))
				{
					$key = array_search($value['id_board'], $id_links); //Search it in links
					if ($key !== FALSE) //Yes, a category was found
						$redirect = $links[$key]['link_url'];
					else
						$redirect = '';
				}
				else
					$redirect = '';
				
				//Everything should be all set now...
				$data_array[] = array(
									'id_board' => $value['id_board'],
									'id_cat' => $parent_categ,
									'child_level' => $child_level,
									'id_parent' => $parent_board,
									'board_order' => $k,
									'id_last_msg' => 0,
									'id_msg_updated' => 0,
									'member_groups' => '-1,0',
									'id_profile' => 1,
									'name' => $value['name'],
									'description' => $value['description'],
									'num_topics' => 0,
									'num_posts' => 0,
									'count_posts' => 0,
									'id_theme' => 0, //default theme everywhere!
									'override_theme' => 0,
									'unapproved_posts' => 0,
									'unapproved_topics' => 0,
									'redirect' => $redirect,
								);
				$k++;
			}
			
			//flush you!
			unset ($boards_temp);
			unset ($links);
			unset ($boards);
			unset ($nodes);
			// echo '<pre>';
			// print_r ($data_array);
			// echo '</pre>';
			
			$smcFunc['db_insert']('insert',
				$prefix_smf . 'boards',
				array(
					'id_board' => 'int',
					'id_cat' => 'int',
					'child_level' => 'int',
					'id_parent' => 'int',
					'board_order' => 'int',
					'id_last_msg' => 'int',
					'id_msg_updated' => 'int',
					'member_groups' => 'string',
					'id_profile' => 'int',
					'name' => 'string',
					'description' => 'string',
					'num_topics' => 'int',
					'num_posts' => 'int',
					'count_posts' => 'int',
					'id_theme' => 'int', //default theme everywhere!
					'override_theme' => 'int',
					'unapproved_posts' => 'int',
					'unapproved_topics' => 'int',
					'redirect' => 'string',
					),
				$data_array,
				array('id_board')
			);
			
			//flush you!
			unset($data_array);
			//Uff, this was hard. Let's move on to posts/topics.!
			echo '</div><h2>The script finished moving ' . $num_boards . ' boards and ' . $num_links . ' "redirect boards" from XF to SMF.<br>
			<a href="' . $script . '?step=4">Please click here to proceed!</a>';
			if ($automated)
			{
				echo ' Or wait 5 seconds to proceed automatically';
				sleep(5);
				echo '<script>window.location = \'' . $script . '?step=4\'</script></h2>';
				die();
			}
			echo '</h2>';			
		break;
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	case 4:
			//Here comes posts and topics... Oh uau...
			echo '<h2>This is the fourth step of the conversion. We are trying to convert Topics and Posts</h2><br>
				First, let\'s trash the actual contents of SMF "topics" and "messages" tables....<br>';
			$result = $smcFunc['db_query']('', '
					TRUNCATE TABLE ' . $prefix_smf . 'topics'
			);
			$result = $smcFunc['db_query']('', '
					TRUNCATE TABLE ' . $prefix_smf . 'messages'
			);
			echo 'Posts and topics cleared from SMF tables. Next step, count what\'s there to move?? <span style="font-weight:bold">';
			$query = '
					SELECT COUNT(t.thread_id) as total
					FROM ' . $prefix_xf . 'thread AS t ';
			//echo '<pre>' . $query . '</pre>';			
			$result = $smcFunc['db_query']('', $query
			);
			$data = $smcFunc['db_fetch_assoc'] ($result);
			$num_topics = $data['total'];
			$smcFunc['db_free_result'] ($result);
			//now, posts
			$query = '
					SELECT COUNT(p.post_id) as total
					FROM ' . $prefix_xf . 'post AS p ';
			//echo '<pre>' . $query . '</pre>';			
			$result = $smcFunc['db_query']('', $query
			);
			$data = $smcFunc['db_fetch_assoc'] ($result);
			$num_posts = $data['total'];
			unset ($data);
			$smcFunc['db_free_result'] ($result);
			echo $num_topics . ' topics and ' . $num_posts . ' posts were found in XF.</span><br>';
			//This will, most likely, exceed (by far) the max_queries we defined earlier, so let's go and honour that...

			$loop_counter = 0;
			$counter = 0;
			$num_loops_topics = (int)($num_topics/$max_queries); //complete number of loops
			$num_loops_posts = (int)($num_posts/$max_queries); //complete number of loops
			$done = false;
			$topics = array();
			//a div with overflow so that the page won't scroll forever...
			echo '<div style="width:100%;height:300px;overflow:scroll;">';
			//Let's convert TOPICS
			while (!$done)
			{
				$lower_limit = ($loop_counter * $max_queries); //This is the starting row
				if ($num_loops_topics > $loop_counter) //is this still a complete set of queries?
					$upper_limit = $max_queries; //number of rows to return
				else
					$upper_limit = $num_topics - ($num_loops_topics * $max_queries); //last trip
					
				echo 'Now retrieving topics ' . ($lower_limit + 1) . ' to ' . ($lower_limit + $upper_limit) . '...<br>';	
				//This is the query to retrieve topics
				$query = '
					SELECT
						t.thread_id AS id_topic,
						t.sticky AS is_sticky,
						t.node_id AS id_board,
						t.first_post_id AS id_first_msg,
						t.last_post_id AS id_last_msg,
						t.user_id AS id_member_started,
						t.last_post_user_id AS id_member_updated,
						0 AS id_poll,
						0 AS id_previous_board,
						0 AS id_previous_topic,
						t.reply_count AS num_replies,
						t.view_count AS num_views,
						COUNT(tr.thread_id) as num_views2,
						CASE
							WHEN t.discussion_open = 0 THEN 1
							WHEN t.discussion_open = 1 THEN 0
							ELSE 0
						END AS locked,
						0 AS unapproved_posts,
						1 AS approved
					FROM ' . $prefix_xf . 'thread AS t
						LEFT JOIN ' . $prefix_xf . 'thread_read as tr ON (tr.thread_id = t.thread_id)
					GROUP BY t.thread_id
					ORDER BY t.thread_id ASC
					LIMIT ' . $lower_limit . ', ' . $upper_limit;
				//echo '<pre>' . $query . '</pre>';
				
				$request = $smcFunc['db_query']('', $query
				);

				while ($row = $smcFunc['db_fetch_assoc']($request))
				{
					$topics[] = $row;
				}
				// echo '<pre>';
				// print_r ($topics);
				// echo '</pre>';
				
				$smcFunc['db_free_result']($request);

				//For each "batch" that we gather we need to dump it to the new table. Shall we?
				//let's build a nice data array 
				$data_array = array();
				foreach ($topics as $key => $value)
				{
					$views = $value['num_views'] + $value['num_views2'];
					$data_array[] = array(
										'id_topic' => $value['id_topic'],
										'is_sticky' => $value['is_sticky'],
										'id_board' => $value['id_board'],
										'id_first_msg' => $value['id_first_msg'],
										'id_last_msg' => $value['id_last_msg'],
										'id_member_started' => $value['id_member_started'],
										'id_member_updated' => $value['id_member_updated'],
										'id_poll' => $value['id_poll'],
										'id_previous_board' => $value['id_previous_board'],
										'id_previous_topic' => $value['id_previous_topic'],
										'num_replies' => $value['num_replies'],
										'num_views' => $views,
										'locked' => $value['locked'],
										'unapproved_posts' => $value['unapproved_posts'],
										'approved' => $value['approved'],
									);
				}
				// echo '<pre>';
				// print_r ($data_array);
				// echo '</pre>';
				
				$smcFunc['db_insert']('insert',
					$prefix_smf . 'topics',
					array(
							'id_topic' => 'int',
							'is_sticky' => 'int',
							'id_board' => 'int',
							'id_first_msg' => 'int',
							'id_last_msg' => 'int',
							'id_member_started' => 'int',
							'id_member_updated' => 'int',
							'id_poll' => 'int',
							'id_previous_board' => 'int',
							'id_previous_topic' => 'int',
							'num_replies' => 'int',
							'num_views' => 'int',
							'locked' => 'int',
							'unapproved_posts' => 'int',
							'approved' => 'int',
						),
					$data_array,
					array('id_member')
				);
				
				//flush you!
				unset($data_array);
				unset($topics);
				
				$loop_counter++; //There ya go, now increase the loop counter
				$counter += $upper_limit; //increase the elements counter. Don't forget we added "1" to lower limit!
				
				//Did we finish or WHAT?!
				if ($counter >= $num_topics)
					$done = true;
				else
					sleep(1);				
			}
			sleep(1);
			$done = false;
			$loop_counter = 0;
			$counter = 0;
			$posts = array();
			//Let's convert POSTS
			while (!$done)
			{
				$lower_limit = ($loop_counter * $max_queries); //This is the starting row
				if ($num_loops_posts > $loop_counter) //is this still a complete set of queries?
					$upper_limit = $max_queries; //number of rows to return
				else
					$upper_limit = $num_posts - ($num_loops_posts * $max_queries); //last trip
					
				echo 'Now retrieving posts ' . ($lower_limit + 1) . ' to ' . ($lower_limit + $upper_limit) . '...<br>';	
				//This is the query to retrieve posts
				$query = '
					SELECT
						p.post_id AS id_msg,
						p.thread_id AS id_topic,
						t.node_id AS id_board,
						p.post_date AS poster_time,
						p.user_id AS id_member,
						p.post_id AS id_msg_modified,
						IF (t.first_post_id = p.post_id, t.title, CONCAT(\'Re: \', t.title)) AS subject,
						m.username AS poster_name,
						m.email AS poster_email,
						\'\' AS poster_ip,
						1 AS smileys_enabled,
						p.last_edit_date AS modified_time,
						m2.username AS modified_name,
						p.message AS body,
						\'xx\' AS icon,
						1 AS approved
					FROM ' . $prefix_xf . 'post AS p
						LEFT JOIN ' . $prefix_xf . 'thread AS t ON (p.thread_id = t.thread_id)
						LEFT JOIN ' . $prefix_xf . 'user AS m ON (p.user_id = m.user_id)
						LEFT JOIN ' . $prefix_xf . 'user AS m2 ON (p.last_edit_user_id = m2.user_id)
					ORDER BY p.post_id ASC
					LIMIT ' . $lower_limit . ', ' . $upper_limit;
//				echo '<pre>' . $query . '</pre>';
				
				$request = $smcFunc['db_query']('', $query
				);

				while ($row = $smcFunc['db_fetch_assoc']($request))
				{
					$posts[] = $row;
				}
				// echo '<pre>';
				// print_r ($posts);
				// echo '</pre>';
				
				$smcFunc['db_free_result']($request);

				//For each "batch" that we gather we need to dump it to the new table. Shall we?
				//let's build a nice data array 
				$data_array = array();
				foreach ($posts as $key => $value)
				{
					$data_array[] = array(
										'id_msg' => $value['id_msg'],
										'id_topic' => $value['id_topic'],
										'id_board' => $value['id_board'],
										'poster_time' => $value['poster_time'],
										'id_member' => $value['id_member'],
										'id_msg_modified' => $value['id_msg_modified'],
										'subject' => $value['subject'],
										'poster_name' => (!empty($value['poster_name']) ? $value['poster_name'] : 'Guest'),
										'poster_email' => (!empty($value['poster_email']) ? $value['poster_email'] : 'guest@invalidemail.abc'),
										'poster_ip' => $value['poster_ip'],
										'smileys_enabled' => $value['smileys_enabled'],
										'modified_time' => $value['modified_time'],
										'modified_name' => (string)$value['modified_name'], //no idea why the cast is needed but I got a strange database errow without it...
										'body' => $value['body'],
										'icon' => $value['icon'],
										'approved' => $value['approved'],
									);
				}
				// echo '<pre>';
				// print_r ($data_array);
				// echo '</pre>';
				
				$smcFunc['db_insert']('insert',
					$prefix_smf . 'messages',
					array(
							'id_msg' => 'int',
							'id_topic' => 'int',
							'id_board' => 'int',
							'poster_time' => 'int',
							'id_member' => 'int',
							'id_msg_modified' => 'int',
							'subject' => 'string',
							'poster_name' => 'string',
							'poster_email' => 'string',
							'poster_ip' => 'string',
							'smileys_enabled' => 'int',
							'modified_time' => 'int',
							'modified_name' => 'string',
							'body' => 'string',
							'icon' => 'string',
							'approved' => 'int',
						),
					$data_array,
					array('id_msg')
				);

				//flush you!
				unset($data_array);
				unset($posts);
				
				$loop_counter++; //There ya go, now increase the loop counter
				$counter += $upper_limit; //increase the elements counter. Don't forget we added "1" to lower limit!
				
				//Did we finish or WHAT?!
				if ($counter >= $num_posts)
					$done = true;
				else
					sleep(1);				
			}

			echo '</div><h2>The script finished moving ' . $num_topics . ' topics and ' . $num_posts . ' posts from XF to SMF.<br>
			<a href="' . $script . '?step=5">Please click here to proceed!</a>';
			if ($automated)
			{
				echo ' Or wait 5 seconds to proceed automatically';
				sleep(5);
				echo '<script>window.location = \'' . $script . '?step=5\'</script></h2>';
				die();
			}
			echo '</h2>';			
		break;
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	case 5:
			//Now we convert PMs
			echo '<h2>This is the fifth step of the conversion. We will now convert Conversations(XF)/PMs(SMF)</h2><br>
				First, let\'s trash the actual contents of SMF "personal_messages" and "pm_recipents" tables....<br>';
			$result = $smcFunc['db_query']('', '
					TRUNCATE TABLE ' . $prefix_smf . 'personal_messages'
			);
			$result = $smcFunc['db_query']('', '
					TRUNCATE TABLE ' . $prefix_smf . 'pm_recipients'
			);
			echo 'PMs cleared from SMF tables. Next step, count what\'s there to move?? <span style="font-weight:bold">';
			$query = '
					SELECT COUNT(m.message_id) as total
					FROM ' . $prefix_xf . 'conversation_message AS m ';
			//echo '<pre>' . $query . '</pre>';			
			$result = $smcFunc['db_query']('', $query
			);
			$data = $smcFunc['db_fetch_assoc'] ($result);
			$num_messages = $data['total'];
			$smcFunc['db_free_result'] ($result);
			unset ($data);
			echo $num_messages . ' PMs were found in XF.</span><br>';
			//This will, most likely, exceeed (by far) the max_queries we defined earlier, so let's go and honour that...

			$loop_counter = 0;
			$counter = 0;
			$num_loops = (int)($num_messages/$max_queries); //complete number of loops

			$done = false;
			$messages = array();
			//a div with overflow so that the page won't scroll forever...
			echo '<div style="width:100%;height:300px;overflow:scroll;">';
			while (!$done)
			{
				$lower_limit = ($loop_counter * $max_queries); //This is the starting row
				if ($num_loops > $loop_counter) //is this still a complete set of queries?
					$upper_limit = $max_queries; //number of rows to return
				else
					$upper_limit = $num_messages - ($num_loops * $max_queries); //last trip

				echo 'Now retrieving messages ' . ($lower_limit + 1) . ' to ' . ($lower_limit + $upper_limit) . '...<br>';	
				//This is the query to retrieve topics
				$query = '
					SELECT
						m.message_id AS id_pm,
						m.conversation_id AS id_pm_head,
						m.user_id AS id_member_from,
						m.username AS from_name,
						m.message_date AS msgtime,
						c.first_message_id AS first_message_id,
						c.title AS subject,
						m.message AS body,
						c.user_id AS id_starter,
						c.last_message_user_id AS last_message_user_id,
						c.recipients AS recipients
					FROM ' . $prefix_xf . 'conversation_message AS m
						LEFT JOIN ' . $prefix_xf . 'conversation_master AS c ON (m.conversation_id = c.conversation_id)
					LIMIT ' . $lower_limit . ', ' . $upper_limit;
				//echo '<pre>' . $query . '</pre>';
				
				$request = $smcFunc['db_query']('', $query);

				while ($row = $smcFunc['db_fetch_assoc']($request))
				{
					$messages[] = $row;
				}
				$smcFunc['db_free_result']($request);
				// echo '<pre>';
				// print_r ($messages);
				// echo '</pre>';

				//For each "batch" that we gather we need to dump it to the new table. Shall we?
				//let's build a nice data array 
				$messages_array = array();
				$recipients_array = array();
				foreach ($messages as $key => $value)
				{
					$messages_array[] = array(
										'id_pm' => $value['id_pm'],
										'id_pm_head' => $value['id_pm_head'],
										'id_member_from' => $value['id_member_from'],
										'deleted_by_sender' => 0, //We will all sent messages as sent 
										'from_name' => $value['from_name'],
										'msgtime' => $value['msgtime'],
										//Add "Re: prefix to subject
										'subject' => $value['id_pm'] == $value['first_message_id'] ? $value['subject'] : 'Re: ' . $value['subject'],
										'body' => $value['body'],
									);
									
					//need to build an array with the IDs of the recipients. Unfortunately they are not all in one place...
					$recipients = array();
					$recipients[] = $value['last_message_user_id']; //This is not in the recipients list...
					if ($value['last_message_user_id'] != $value['id_starter']) //prevent duplicates
						$recipients[] = $value['id_starter'];
					$temp = unserialize($value['recipients']);
					foreach ($temp as $key2 => $value2)
					{
						if (($key2 != $value['last_message_user_id']) && ($key2 != $value['id_starter'])) //prevent duplicates
							$recipients[] = $key2;
					}	
					unset($temp);

					// echo '<pre>';
					// echo 'Message: ' . $value['id_pm'] . ': ';
					// print_r($recipients);
					// echo '</pre>';
					
					foreach ($recipients as $temp)
					{
						if ($temp != $value['id_member_from']) //We don't want to put ourselves in the destination :)
						{
							$recipients_array[] = array(
											'id_pm' => $value['id_pm'],
											'id_member' => $temp,
											'labels' => '-1',
											'bcc' => 0,
											'is_read' => 1, //No new messages after conversion, sorry about that :)
											'is_new' => 0,
											'deleted' => 0,
										);
						}				
					}
					unset($recipients);					
				}
				
				// echo '<pre>';
				// print_r ($messages_array);
				// print_r ($recipients_array);
				// echo '</pre>';

				//And now, fill our SMF tables :)
				$smcFunc['db_insert']('insert',
					$prefix_smf . 'personal_messages',
					array(
							'id_pm' => 'int',
							'id_pm_head' => 'int',
							'id_member_from' => 'int',
							'deleted_by_sender' => 'int',
							'from_name' => 'string',
							'msgtime' => 'int',
							'subject' => 'string',
							'body' => 'string',
						),
					$messages_array,
					array('id_pm')
				);
				$smcFunc['db_insert']('insert',
					$prefix_smf . 'pm_recipients',
					array(
							'id_pm' => 'int',
							'id_member' => 'int',
							'labels' => 'string',
							'bcc' => 'int',
							'is_read' => 'int',
							'is_new' => 'int',
							'deleted' => 'int',
						),
					$recipients_array,
					array('id_pm', 'id_member')
				);

				//flush you!
				unset($messages_array);
				unset($recipients_array);
				unset($messages);

					
				$loop_counter++; //There ya go, now increase the loop counter
				$counter += $upper_limit; //increase the elements counter. Don't forget we added "1" to lower limit!
				
				//Did we finish or WHAT?!
				if ($counter >= $num_messages)
					$done = true;
				else
					sleep(1);				
			}
			sleep(1);			

			echo '</div><h2>The script finished moving ' . $num_messages . ' Private Messages from XF to SMF.<br>
			<a href="' . $script . '?step=6">Please click here to proceed!</a>';
			if ($automated)
			{
				echo ' Or wait 5 seconds to proceed automatically';
				sleep(5);
				echo '<script>window.location = \'' . $script . '?step=6\'</script></h2>';
				die();
			}
			echo '</h2>';			
		break;
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	case 6:
			//Now we convert Post Attachments
			echo '<h2>This is the sixth step of the conversion. We will now copy and convert Post Attachments</h2><br>
				First, let\'s trash the actual contents of SMF "attachments" table....<br>';
			$result = $smcFunc['db_query']('', '
					TRUNCATE TABLE ' . $prefix_smf . 'attachments'
			);
			echo 'Attachments table cleared. Next step, delete any existing files in the destination folder.<br>';
			array_map('unlink', glob($smf_dir . '/attachments/*'));
			echo 'Attachments folder is now empty. Now we will start the copy operation.<br>
					Depending on the number of files you have, this can take some time...';
			
			//Damn, this will be tricky... First of all, get all the posts that DO have attachments registered to it.
			//Or course this will exceed our "max_queries" so we should honour that
			$last_id = 0;
			$done = false;
			$num_attachments = 0;
			$num_attachments_old = 0;
			$num_copy_errors = 0;
			//a div with overflow so that the page won't scroll forever...
			echo '<div style="width:100%;height:300px;overflow:scroll;">';			
			while(!$done)
			{
				$query = '
						SELECT p.post_id AS post_id,
							p.attach_count AS attach_count
						FROM ' . $prefix_xf . 'post AS p
						WHERE p.attach_count > 0
							AND p.post_id > ' . $last_id . '
						ORDER BY p.post_id
						LIMIT 0, ' . $max_queries;
				//echo '<pre>' . $query . '</pre>';
				
				$result = $smcFunc['db_query']('', $query);
				$temp_post_ids = array();
				while ($row = $smcFunc['db_fetch_assoc']($result))
				{
					$temp_post_ids[] = $row;
				}
				$smcFunc['db_free_result'] ($result);
				// echo '<pre>';
				// print_r($temp_post_ids);
				// echo '</pre>';
				
				//Let's do some heavy work...
				if (!empty($temp_post_ids))
				{
					
					reset($temp_post_ids);
					$num_attach_cycle = count($temp_post_ids);
					//Just something for the display...
					$num_attachments += $num_attach_cycle;
					echo 'Copying attachments ' . ($num_attachments_old + 1) . ' to ' . $num_attachments . '...';
					$num_attachments_old = $num_attachments;
					//Get the last recorded id for the next iteration
					$last_id_temp = end($temp_post_ids);
					$last_id = $last_id_temp['post_id'];
					// echo '<pre>' . $last_id . '</pre>';
					
					
					//For each message we will get its attachments. Probably not very efficient, but it will do the trick :)
					foreach ($temp_post_ids as $temp)
					{
						// echo '<pre>';
						// print_r($temp);
						// echo '</pre>';
					
						//Get the attachments details related to this post ID
						$query = '
								SELECT a.attachment_id AS id_attach,
									a.data_id AS data_id,
									a.content_id AS id_msg,
									a.view_count AS view_count,
									ad.user_id AS user_id,
									ad.filename AS filename,
									ad.file_size AS filesize,
									ad.file_hash AS file_hash,
									ad.width AS width,
									ad.height AS height
								FROM ' . $prefix_xf . 'attachment AS a
									INNER JOIN ' . $prefix_xf . 'attachment_data AS ad ON (ad.data_id = a.data_id)
								WHERE a.content_type = \'post\'
									AND a.content_id = ' . $temp['post_id'] . '
								ORDER BY a.attachment_id';
								
						// echo '<pre>' . $query . '</pre>';
						
						$result = $smcFunc['db_query']('', $query);
						$attach_data = array();
						while ($row = $smcFunc['db_fetch_assoc']($result))
						{
							$attach_data[] = $row;
						}
						$smcFunc['db_free_result'] ($result);					
						// echo '<pre>';
						// print_r($attach_data);
						// echo '</pre>';
						//And, for each of the retrieved files, a LOT of things to do...
						//Lets run yet another foreach cycle
						foreach($attach_data as $temp2)
						{
							// echo '<pre>';
							// print_r($temp2);
							// echo '</pre>';
							//Now, we need to get the hashed file name from XF and convert it to a lovely filename. One we can actually use, ya know? :)
							$oriname = $xf_dir . '/internal_data/attachments/0/' . $temp2['data_id'] . '-' . $temp2['file_hash'] . '.data';
							// echo '<pre>' . $oriname	 . '</pre>';
							//Destination (temporary) name
							$destname = $smf_dir . '/attachments/' . $temp2['filename'];
							$test = copy($oriname, $destname);
							if (!$test) //Just an error counter, can be improved but not very important for now...
							{
								$num_copy_errors++;
								continue; //skip the rest of the cycle
							}
							
							//Now that we've copied the file, lets add to the database. Our modified SMF function should take care of everything...
							$attachmentOptions = array(
								'post' => $temp2['id_msg'],
								'poster' => $temp2['user_id'],
								'name' => $temp2['filename'],
								'tmp_name' => $temp2['filename'],
								'size' => $temp2['filesize'],
								'width' => $temp2['width'],
								'height' => $temp2['height'],
								'downloads' => $temp2['view_count'],
								'approved' => 1,  
								'is_avatar' => 0, 
							);
							$test = createAttachment($attachmentOptions);
							if (!$test)
								$num_copy_errors++;
							// echo '<pre>' . $test	 . '</pre>';
							// echo '<pre>';
							// print_r($attachmentOptions);
							// echo '</pre>';
							
						
						
						}					
						unset($attach_data);
					}
					unset($temp_post_ids);
					//Finish the display...
					echo 'Done<br>';
					//Some rest to mysql...
					sleep(1);
				}
				else
					//Nothing gathered, done
					$done = true;				
			}
			echo '</div><h2>The script finished moving ' . $num_attachments . ' attachments from XF to SMF.<br>';
				if ($num_copy_errors > 0) echo 'Do note, some errors were found while converting files....';
			echo '
				<a href="' . $script . '?step=7">Please click here to proceed!</a>';
			if ($automated)
			{
				echo ' Or wait 5 seconds to proceed automatically';
				sleep(5);
				echo '<script>window.location = \'' . $script . '?step=7\'</script></h2>';
				die();
			}
			echo '</h2>';			
		break;
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////		
	case 7:
			//Now we convert User avatars
			echo '<h2>This is the seventh step of the conversion. We will now copy and convert User Avatars</h2><br>
				Please make sure that you\'ve reached here from the sixth step (Post Attachments) as both work the same<br>
				table in SMF\'s structure so it\'s really important the sequence is maintained.<br>';

			echo 'We will now try and fetch all existing avatars datas, from XF\'s data/avatars/l sub-folders<br>
					It can happen that this results in a huge array. If memory is exceeded, this will need a serious overhaul...<br>';

			//echo memory_get_usage() . "\n";
					
			//So, let's gather ALL avatars... nham, memory eater :)
			$temp_avatars = getAvatars('l',0); //big avatars folder
			// echo '<pre>';
			// print_r($temp_avatars);
			// echo '</pre>';
			
			//The array returned from getAvatars is a BIG mess, isn't it? So, let's make this simple
			$avatars = array();
			foreach($temp_avatars as $key => $value)
			{
				$folder = $xf_dir . '/data/avatars/l/' . ($value['filename'] == 'zzz' ? '0' : $value['filename']);
				//echo($folder) . '<br>';
				//print_r($value);
				//Files data 
				foreach ($value['files'] as $key2 => $value2)
				{
					$filepath = $folder . '/' . $value2['filename'];
					//images size. We don't need protection, XF should have done that for us :)
					$sizes = @getimagesize($filepath);
					//print_r($value2);
					$avatars[] = array(
									'folder' => $folder,
									'filepath' => $filepath,
									'name' => $value2['name'],
									'filename' => $value2['filename'],
									'size_width' => $sizes[0],
									'size_height' => $sizes[1],
								);
				}
			}
			// echo '<pre>';
			// print_r($avatars);
			// echo '</pre>';
			//save memory is needed
			unset($temp_avatars);
			
			//Or course this will exceed our "max_queries" so we should honour that
			$num_avatars = count($avatars);
			echo 'Now we will start the copy operation.	<b>' . $num_avatars . ' avatars found</b>. Depending on this number of files, this can take some time...';
			$num_cycles = (int)($num_avatars / $max_queries);
			if (($num_avatars % $max_queries) != 0)
				$num_cycles++;
			$count_cycles = 0;
			$count_success = 0;
			$num_rounds = 0;
			//a div with overflow so that the page won't scroll forever...
			echo '<div style="width:100%;height:300px;overflow:scroll;">';			
			//Here's how this works... The "avatars" array is a totally ordered one. So we will just go step by step.
			//For each entry we work the file. Easy, right? Yeah, sure... :P
			foreach ($avatars as $key => $value)
			{
				// echo '<pre>';
				// print_r($value);
				// echo '</pre>';
				if ($count_cycles == 0)
					echo 'Passage ' . ($num_rounds + 1) . ' of ' . $num_cycles . '... ';
				//First: check if the user ID exists in our already imported members database
				$query = '
						SELECT COUNT(m.id_member) as total
						FROM ' . $prefix_smf . 'members AS m
						WHERE m.id_member = ' . $value['name'];
				//echo '<pre>' . $query . '</pre>';			
				$result = $smcFunc['db_query']('', $query
				);
				$data = $smcFunc['db_fetch_assoc'] ($result);
				$num_members = $data['total'];
				$smcFunc['db_free_result'] ($result);
				unset ($data);
				//echo '<pre>' . $num_members . '</pre>';
				//Now, if this returned anything but 1 member, something is damaged, just skip ahead!
				if ($num_members != 1)
					continue;
				

				// Now that we know that our member exists, we need to hash the file, copy it and update "attachments" table...
				$attachmentOptions = array(
					'post' => 0, //No post, of course :)
					'poster' => $value['name'],
					'name' => $value['filename'],
					'tmp_name' => $value['filepath'],
					'size' => '',							//function createAttachment will take care of this
					'width' => $value['size_width'],
					'height' => $value['size_height'],
					'downloads' => 0,
					'approved' => 1,
					'is_avatar' => 1, 
				);
				$test = createAttachment($attachmentOptions);
				// echo '<pre>' . $test . '</pre>';
				// echo '<pre>';
				// print_r($attachmentOptions);
				// echo '</pre>';
				if (!$test)
					continue;

				if ($count_cycles == 0)
					echo 'Done!<br>';
				$count_success++;
				//Another way to pause our script every "max_queries"
				$count_cycles++;
				if ($count_cycles >= $max_queries)
				{
					$count_cycles = 0;
					$num_rounds++;
					sleep(1);				
				}

			}
				
			//Finish!!!
			echo '</div><h2>The script finished moving ' . $count_success . ' attachments from XF to SMF.<br>
			<a href="' . $script . '?step=8">Please click here to proceed!</a>';
			if ($automated)
			{
				echo ' Or wait 5 seconds to proceed automatically';
				sleep(5);
				echo '<script>window.location = \'' . $script . '?step=8\'</script></h2>';
				die();
			}
			echo '</h2>';			
		break;
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////		
	case 8:
			//Now we convert Polls
			echo '<h2>This is the eight step of the conversion. We will now convert Polls</h2><br>
				First, let\'s trash the actual contents of SMF "polls", "poll_choices" and "log_polls" tables....<br>';
			//We need to ALTER the "smf_poll_choices" table, in order add a column to store the previous response ID...
			$test = $smcFunc['db_list_columns'] ($prefix_smf . 'poll_choices');
			if (!in_array('old_response', $test))
				$smcFunc['db_add_column']($prefix_smf . 'poll_choices', array('name' => 'old_response', 'size' => 8, 'type' => 'mediumint', 'null' => false));
			unset($test);
			$result = $smcFunc['db_query']('', '
					TRUNCATE TABLE ' . $prefix_smf . 'polls'
			);
			$result = $smcFunc['db_query']('', '
					TRUNCATE TABLE ' . $prefix_smf . 'poll_choices'
			);
			$result = $smcFunc['db_query']('', '
					TRUNCATE TABLE ' . $prefix_smf . 'log_polls'
			);
			echo 'Polls cleared from SMF tables. Next step, count what\'s there to move?? <span style="font-weight:bold">';
			$query = '
					SELECT COUNT(p.poll_id) as total
					FROM ' . $prefix_xf . 'poll AS p
					WHERE p.content_type = \'thread\'';
			//echo '<pre>' . $query . '</pre>';
			$result = $smcFunc['db_query']('', $query
			);
			$data = $smcFunc['db_fetch_assoc'] ($result);
			$num_polls = $data['total'];
			$smcFunc['db_free_result'] ($result);
			$query = '
					SELECT COUNT(pr.poll_response_id) as total
					FROM ' . $prefix_xf . 'poll_response AS pr';
			//echo '<pre>' . $query . '</pre>';
			$result = $smcFunc['db_query']('', $query
			);
			$data = $smcFunc['db_fetch_assoc'] ($result);
			$num_poll_responses = $data['total'];
			$smcFunc['db_free_result'] ($result);			
			//echo '<pre>' . $num_poll_responses . '</pre>';
			$query = '
					SELECT COUNT(pv.user_id) as total
					FROM ' . $prefix_xf . 'poll_vote AS pv';
			//echo '<pre>' . $query . '</pre>';
			$result = $smcFunc['db_query']('', $query
			);
			$data = $smcFunc['db_fetch_assoc'] ($result);
			$num_poll_votes = $data['total'];
			$smcFunc['db_free_result'] ($result);			
			//echo '<pre>' . $num_poll_votes . '</pre>';
			
			echo $num_polls . ' polls, ' . $num_poll_responses . ' poll responses and ' . $num_poll_votes . ' poll votes were found in XF.</span><br />';
			//This will, most likely, exceed (by far) the max_queries we defined earlier, so let's go and honour that...
			$loop_counter = 0;
			$counter = 0;
			$num_loops = (int)($num_polls/$max_queries); //complete number of loops
			$done = false;
			$now = time(); //We will need to know what day is today for... things :P
			//a div with overflow so that the page won't scroll forever...
			echo '<div style="width:100%;height:300px;overflow:scroll;">';
			while (!$done)
			{
				$lower_limit = ($loop_counter * $max_queries); //This is the starting row
				if ($num_loops > $loop_counter) //is this still a complete set of queries?
					$upper_limit = $max_queries; //number of rows to return
				else
					$upper_limit = $num_polls - ($num_loops * $max_queries); //last trip
					
				echo 'Now retrieving polls ' . ($lower_limit + 1) . ' to ' . ($lower_limit + $upper_limit) . '...<br>';	
				//This is the query to retrieve polls
				$query = '
					SELECT
						p.poll_id AS poll_id,
						p.content_id AS content_id,
						p.question AS question,
						p.voter_count AS voter_count,
						p.public_votes as public_votes,
						p.multiple AS multiple,
						p.close_date AS close_date,
						pt.user_id AS user_id,
						pt.username AS username
					FROM ' . $prefix_xf . 'poll AS p
						LEFT JOIN ' . $prefix_xf . 'thread AS pt ON (pt.thread_id = p.content_id)
					WHERE p.content_type = \'thread\'
					ORDER BY p.poll_id ASC
					LIMIT ' . $lower_limit . ', ' . $upper_limit;
				//echo '<pre>' . $query . '</pre>';
				
				$request = $smcFunc['db_query']('', $query
				);
				$polls = array();
				while ($row = $smcFunc['db_fetch_assoc']($request))
				{
					$polls[] = $row;
				}
				// echo '<pre>';
				// print_r ($polls);
				// echo '</pre>';
				$smcFunc['db_free_result']($request);

				//For each "batch" that we gather we need to dump it to the new tables. Shall we?
				//let's build a nice data array
				$data_array = array();
				foreach ($polls as $key => $value)
				{
					// echo '<pre>';
					// print_r ($value);
					// echo '</pre>';
					
					//We need do do a LOT of things here. And I've never used polls in my life :P
					//First, update the topic with the poll ID information...
					$smcFunc['db_query']('', '
						UPDATE ' . $prefix_smf . 'topics
						SET id_poll = ' . $value['poll_id'] . '
						WHERE id_topic = ' . $value['content_id']
					);
					//Now, XF doesn't allow to lock a poll without locking the topic, so it seems.
					//Right now we'll just leave it locked to topic or not, because that's also how it works in XF. Nothing to do, here :P
					
					//Next, XF also doesn't allow to choose how many replies a user can give to a poll. But SMF does. Fortunately, SMF is smart and it will
					//limit this stupid big number :)
					if (empty($value['multiple']))
						$max_votes = 99;
					else
						$max_votes = 1;
						
					//And we add to our beautiful data array :P
					$temp = array(
						'id_poll' => $value['poll_id'],
						'question' => $value['question'],
						'voting_locked' => 0,
						'max_votes' => $max_votes,
						'expire_time' => $value['close_date'],
						'hide_results' => 0, //Didn't find that option in XF...
						'change_vote' => 0, //also not
						'guest_vote' => 0, //also not
						'num_guest_voters' => 0, //sorry bub
						'reset_poll' => 0,
						'id_member' => $value['user_id'],
						'poster_name' => $value['username'],
					);
					$data_array[] = $temp;

				}
				// echo '<pre>';
				// print_r ($data_array);
				// echo '</pre>';				
				//All is good, to the database you go!
				$smcFunc['db_insert']('insert',
					$prefix_smf . 'polls',
					array(
							'id_poll' => 'int',
							'question' => 'string',
							'voting_locked' => 'int',
							'max_votes' => 'int',
							'expire_time' => 'int',
							'hide_results' => 'int',
							'change_vote' => 'int',
							'guest_vote' => 'int',
							'num_guest_voters' => 'int',
							'reset_poll' => 'int',
							'id_member' => 'int',
							'poster_name' => 'string',
						),
					$data_array,
					array('id_poll')
				);
				
				//flush you!
				unset($data_array);
				unset($polls);
				$loop_counter++; //There ya go, now increase the loop counter
				$counter += $upper_limit; //increase the elements counter. Don't forget we added "1" to lower limit!
				
				//Did we finish or WHAT?!
				if ($counter >= $num_polls)
					$done = true;
				else
					sleep(1);				
			}				
			//We finished polls... Next --> poll responses... Oh well...
			sleep(1);
			//This will, most likely, exceed (by far) the max_queries we defined earlier, so let's go and honour that...
			$loop_counter = 0;
			$counter = 0;
			$num_loops = (int)($num_polls/$max_queries); //complete number of loops
			$done = false;
			while (!$done)
			{
				$lower_limit = ($loop_counter * $max_queries); //This is the starting row
				if ($num_loops > $loop_counter) //is this still a complete set of queries?
					$upper_limit = $max_queries; //number of rows to return
				else
					$upper_limit = $num_polls - ($num_loops * $max_queries); //last trip
				echo 'Now retrieving poll responses from polls ' . ($lower_limit + 1) . ' to ' . ($lower_limit + $upper_limit) . '...<br>';	
				$poll_responses = array();
				//Ahhh, why isn't this ever easy :P
				//Get $max queries of poll ids
				$query = '
					SELECT
						pr.poll_id AS poll_id
					FROM ' . $prefix_xf . 'poll_response AS pr
						LEFT JOIN ' . $prefix_xf . 'poll AS p ON (pr.poll_id = p.poll_id)
					WHERE p.content_type = \'thread\'
					GROUP BY pr.poll_id
					ORDER BY pr.poll_response_id ASC
					LIMIT ' . $lower_limit . ', ' . $upper_limit;
				//echo '<pre>' . $query . '</pre>';
				$request = $smcFunc['db_query']('', $query
				);

				while ($row = $smcFunc['db_fetch_assoc']($request))
				{
					$poll_responses[] = $row;
				}
				// echo '<pre>';
				// print_r ($poll_responses);
				// echo '</pre>';
				$smcFunc['db_free_result']($request);
				//Build an array of simple poll IDs...
				$poll_ids = array();
				foreach ($poll_responses as $key => $value)
					$poll_ids[] = $value['poll_id'];
				unset($poll_responses);
				$poll_responses = array();
				//Now, we get the responses for those specific poll_ids.
				$query = '
					SELECT
						pr.poll_response_id AS poll_response_id,
						pr.poll_id AS poll_id,
						pr.response AS response,
						pr.response_vote_count AS response_vote_count
					FROM ' . $prefix_xf . 'poll_response AS pr
					WHERE pr.poll_id IN ({array_int:list_polls})';
				$request = $smcFunc['db_query']('', $query ,
					array(
						'list_polls' => $poll_ids,
					)
				);
				while ($row = $smcFunc['db_fetch_assoc']($request))
					$poll_responses[] = $row;
				// echo '<pre>';
				// print_r ($poll_responses	);
				// echo '</pre>';
				$smcFunc['db_free_result']($request);
				//For each "batch" that we gather we need to dump it to the new tables. Shall we?
				//let's build a nice data array
				$data_array = array();
				$response_id = 0;
				$old_poll_id = 0;
				$temp_choice_id = 0;
				foreach ($poll_responses as $key => $value)
				{
	
					//A little trick to convert the choice id. Again, why this have to be so different? Blame SMF here, for not having an AI id_choice...
					if ($old_poll_id != $value['poll_id']) //Change in poll ID
					{
						$temp_choice_id = 0;
						$old_poll_id = $value['poll_id'];
					}
					else
						$temp_choice_id++;
					
					//And we add to our beautiful data array :P
					$temp = array(
						'id_poll' => $value['poll_id'],
						'id_choice' => $temp_choice_id,
						'label' => $value['response'],
						'votes' => $value['response_vote_count'],
						'old_response' => $value['poll_response_id'],
					);
					$data_array[] = $temp;
				}
				unset($poll_responses);
				unset($poll_ids);
				// echo '<pre>';
				// print_r ($data_array);
				// echo '</pre>';				
				//All is good, to the database you go!
				$smcFunc['db_insert']('insert',
					$prefix_smf . 'poll_choices',
					array(
							'id_poll' => 'int',
							'id_choice' => 'int',
							'label' => 'string',
							'votes' => 'int',
							'old_response' => 'int',
						),
					$data_array,
					array()
				);
				unset($data_array);
				$loop_counter++; //There ya go, now increase the loop counter
				$counter += $upper_limit; //increase the elements counter. Don't forget we added "1" to lower limit!
				
				//Did we finish or WHAT?!
				if ($counter >= $num_polls)
					$done = true;
				else
					sleep(1);		
			}

			//Now, for votes... Jeez!
			sleep(1);
			//Same old, same old...
			$loop_counter = 0;
			$counter = 0;
			$num_loops = (int)($num_poll_votes/$max_queries); //complete number of loops
			$done = false;
			while(!$done)
			{
				$lower_limit = ($loop_counter * $max_queries); //This is the starting row
				if ($num_loops > $loop_counter) //is this still a complete set of queries?
					$upper_limit = $max_queries; //number of rows to return
				else
					$upper_limit = $num_poll_votes - ($num_loops * $max_queries); //last trip
				echo 'Now retrieving poll votes ' . ($lower_limit + 1) . ' to ' . ($lower_limit + $upper_limit) . '...<br>';			
				//This is the query to retrieve poll votes
				$query = '
					SELECT
						pv.user_id AS user_id,
						pv.poll_response_id AS poll_response_id,
						pv.poll_id AS poll_id,
						pc.old_response AS old_response,
						pc.id_choice AS id_choice
					FROM ' . $prefix_xf . 'poll_vote AS pv
						LEFT JOIN ' . $prefix_smf . 'poll_choices AS pc ON (pv.poll_response_id = pc.old_response)
					ORDER BY pv.poll_response_id ASC
					LIMIT ' . $lower_limit . ', ' . $upper_limit;
				//echo '<pre>' . $query . '</pre>';
				
				$request = $smcFunc['db_query']('', $query
				);
				$poll_votes = array();
				while ($row = $smcFunc['db_fetch_assoc']($request))
					$poll_votes[] = $row;
				// echo '<pre>';
				// print_r ($poll_votes);
				// echo '</pre>';
				$smcFunc['db_free_result']($request);				
			
				$data_array = array();
				foreach ($poll_votes as $key => $value)
				{
	
					//A little trick to convert the choice id. Again, why this have to be so different? Blame SMF here, for not having an AI id_choice...
					if ($old_poll_id != $value['poll_id']) //Change in poll ID
					{
						$temp_choice_id = 0;
						$old_poll_id = $value['poll_id'];
					}
					else
						$temp_choice_id++;
					
					//And we add to our beautiful data array :P
					$temp = array(
						'id_poll' => $value['poll_id'],
						'id_member' => $value['user_id'],
						'id_choice' => $value['id_choice'],
					);
					$data_array[] = $temp;
				}
				unset($poll_votes);
				// echo '<pre>';
				// print_r ($data_array);
				// echo '</pre>';				
				//All is good, to the database you go!
				$smcFunc['db_insert']('insert',
					$prefix_smf . 'log_polls',
					array(
							'id_poll' => 'int',
							'id_member' => 'int',
							'id_choice' => 'string',
						),
					$data_array,
					array()
				);
				unset($data_array);			

				$loop_counter++; //There ya go, now increase the loop counter
				$counter += $upper_limit; //increase the elements counter. Don't forget we added "1" to lower limit!
				
				//Did we finish or WHAT?!
				if ($counter >= $num_poll_votes)
					$done = true;
				else
					sleep(1);		
			
			}
			
			
			//Finish!!!
			//Remove the temporary column created for the old response ID in poll_choices
			//$smcFunc['db_remove_column']($prefix_smf . 'poll_choices', 'old_response');
			echo '</div><h2>The script finished moving Polls/Responses/Votes from XF to SMF.<br>
			<a href="' . $script . '?step=9">Please click here to proceed!</a>';
			if ($automated)
			{
				echo ' Or wait 5 seconds to proceed automatically';
				sleep(5);
				echo '<script>window.location = \'' . $script . '?step=9\'</script></h2>';
				die();
			}
			echo '</h2>';			
		break;
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	case 9:
			//We finished, for now.
			echo '<h2>This is, for now, the end of the conversion. <br>
					You should now login to your new SMF forum (NOTE: YOU NEED TO RECOVER YOUR PASSWORD!) and go to:</h2><br>
					--> ACP --> Maintenance --> Recount all forum totals and statistics<br><br>
					This will update your forum totals.<br><br>
					<h2><a href="' . $boardurl . '/index.php">Please click here to proceed!</a></h2>';
		break;
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	default:
			echo '<h1>Ups, something went wrong, wou\'re not supposed to be here. Please start over...</h1><br>
					<h2><a href="' . $script . '">Please click here</a>';
	
		break;
}


//Create attachment --> lame copy of the same function in Subs-Post.php
function createAttachment(&$attachmentOptions)
{
	global $smf_dir, $prefix_smf, $smcFunc;
	require_once($smf_dir . '/Sources/Subs-Graphics.php');
	
	//Fixed size for thumbs. Why not?
	$attachmentThumbWidth = 100;
	$attachmentThumbHeight = 75;

	// We need to know where this thing is going.
	$attach_dir = $smf_dir . '/attachments';
	$id_folder = 1;
	
	//We also need to correct the complete files path
	//$attachmentOptions['tmp_name'] = $attach_dir . '/' . $attachmentOptions['tmp_name'];

	$attachmentOptions['errors'] = array();
	if (!isset($attachmentOptions['post']))
		$attachmentOptions['post'] = 0;
	if (!isset($attachmentOptions['approved']))
		$attachmentOptions['approved'] = 1;

	//$already_uploaded = preg_match('~^post_tmp_' . $attachmentOptions['poster'] . '_\d+$~', $attachmentOptions['tmp_name']) != 0;
	if (empty($attachmentOptions['is_avatar'])) //Aren't we an avatar?
		$already_uploaded = 1; //Of course it is uploaded. We did it! :)
	else
		$already_uploaded = 0;
	$file_restricted = @ini_get('open_basedir') != '' && !$already_uploaded;

	if ($already_uploaded)
		$attachmentOptions['tmp_name'] = $attach_dir . '/' . $attachmentOptions['tmp_name'];

	// These are the only valid image types for SMF.
	$validImageTypes = array(
		1 => 'gif',
		2 => 'jpeg',
		3 => 'png',
		5 => 'psd',
		6 => 'bmp',
		7 => 'tiff',
		8 => 'tiff',
		9 => 'jpeg',
		14 => 'iff'
	);

	if (!$file_restricted || $already_uploaded)
	{
		//This was imported from XF
		$size = @getimagesize($attachmentOptions['tmp_name']);
//		list ($attachmentOptions['width'], $attachmentOptions['height']) = $size;

		// If it's an image get the mime type right.
		if (empty($attachmentOptions['mime_type']) && $attachmentOptions['width'])
		{
			// Got a proper mime type?
			if (!empty($size['mime']))
				$attachmentOptions['mime_type'] = $size['mime'];
			// Otherwise a valid one?
			elseif (isset($validImageTypes[$size[2]]))
				$attachmentOptions['mime_type'] = 'image/' . $validImageTypes[$size[2]];
		}
	}
	
	//If we didn't supply this before, do it now.
	if (empty($attachmentOptions['size']))
		$attachmentOptions['size'] = @filesize($attachmentOptions['tmp_name']);

	// create an hash for the file. 
	$attachmentOptions['file_hash'] = sha1(md5($attachmentOptions['name'] . time()) . mt_rand());

	// Assuming no-one set the extension let's take a look at it.
	if (empty($attachmentOptions['fileext']))
	{
		$attachmentOptions['fileext'] = strtolower(strrpos($attachmentOptions['name'], '.') !== false ? substr($attachmentOptions['name'], strrpos($attachmentOptions['name'], '.') + 1) : '');
		if (strlen($attachmentOptions['fileext']) > 8 || '.' . $attachmentOptions['fileext'] == $attachmentOptions['name'])
			$attachmentOptions['fileext'] = '';
	}

	$smcFunc['db_insert']('',
		$prefix_smf . 'attachments',
		array(
			'id_folder' => 'int', 'id_msg' => 'int', 'id_member' => 'int', 'filename' => 'string-255', 'file_hash' => 'string-40', 'fileext' => 'string-8',
			'size' => 'int', 'width' => 'int', 'height' => 'int',
			'mime_type' => 'string-20', 'approved' => 'int', 'downloads' => 'int',
		),
		array(
			$id_folder, (int) $attachmentOptions['post'], (empty($attachmentOptions['is_avatar']) ? 0 : (int)($attachmentOptions['poster'])),
			$attachmentOptions['name'], $attachmentOptions['file_hash'], $attachmentOptions['fileext'],
			(int) $attachmentOptions['size'], (empty($attachmentOptions['width']) ? 0 : (int) $attachmentOptions['width']), (empty($attachmentOptions['height']) ? '0' : (int) $attachmentOptions['height']),
			(!empty($attachmentOptions['mime_type']) ? $attachmentOptions['mime_type'] : ''), (int) $attachmentOptions['approved'], (int) $attachmentOptions['downloads'],
		),
		array('id_attach')
	);
	$attachmentOptions['id'] = $smcFunc['db_insert_id']($prefix_smf . 'attachments', 'id_attach');

	if (empty($attachmentOptions['id']))
		return false;

	$attachmentOptions['destination'] = getAttachmentFilename(basename($attachmentOptions['name']), $attachmentOptions['id'], $id_folder, false, $attachmentOptions['file_hash']);
	
	//Now that we have a name, let's move the original to the hashed one.
	if (empty($attachmentOptions['is_avatar']))
		rename($attachmentOptions['tmp_name'], $attachmentOptions['destination']);
	else
		//Let's not move attachments from XF, right? :)
		copy($attachmentOptions['tmp_name'], $attachmentOptions['destination']);

	// Attempt to chmod it. Not needed, user has to to do...
	//@chmod($attachmentOptions['destination'], 0644);

	//$size = @getimagesize($attachmentOptions['destination']);
	//list ($attachmentOptions['width'], $attachmentOptions['height']) = empty($size) ? array(null, null, null) : $size;

	// No security checks for images, XF covered us here, right? :)

	if (!empty($attachmentOptions['is_avatar']) || (empty($attachmentOptions['width']) && empty($attachmentOptions['height'])))
		return true;

	// Like thumbnails, do we?
	if ($attachmentOptions['width'] > $attachmentThumbWidth || $attachmentOptions['height'] > $attachmentThumbHeight)
	{
		if (createThumbnail($attachmentOptions['destination'], $attachmentThumbWidth, $attachmentThumbHeight))
		{
			// Figure out how big we actually made it.
			$size = @getimagesize($attachmentOptions['destination'] . '_thumb');
			list ($thumb_width, $thumb_height) = $size;

			if (!empty($size['mime']))
				$thumb_mime = $size['mime'];
			elseif (isset($validImageTypes[$size[2]]))
				$thumb_mime = 'image/' . $validImageTypes[$size[2]];
			// Lord only knows how this happened...
			else
				$thumb_mime = '';

			$thumb_filename = $attachmentOptions['name'] . '_thumb';
			$thumb_size = filesize($attachmentOptions['destination'] . '_thumb');
			$thumb_file_hash = sha1(md5($thumb_filename . time()) . mt_rand());

			// To the database we go!
			$smcFunc['db_insert']('',
				$prefix_smf . 'attachments',
				array(
					'id_folder' => 'int', 'id_msg' => 'int', 'attachment_type' => 'int', 'filename' => 'string-255', 'file_hash' => 'string-40', 'fileext' => 'string-8',
					'size' => 'int', 'width' => 'int', 'height' => 'int', 'mime_type' => 'string-20', 'approved' => 'int',
				),
				array(
					$id_folder, (int) $attachmentOptions['post'], 3, $thumb_filename, $thumb_file_hash, $attachmentOptions['fileext'],
					$thumb_size, $thumb_width, $thumb_height, $thumb_mime, (int) $attachmentOptions['approved'],
				),
				array('id_attach')
			);
			$attachmentOptions['thumb'] = $smcFunc['db_insert_id']($prefix_smf . 'attachments', 'id_attach');

			if (!empty($attachmentOptions['thumb']))
			{
				$smcFunc['db_query']('', '
					UPDATE ' . $prefix_smf . 'attachments
					SET id_thumb = {int:id_thumb}
					WHERE id_attach = {int:id_attach}',
					array(
						'id_thumb' => $attachmentOptions['thumb'],
						'id_attach' => $attachmentOptions['id'],
					)
				);

				rename($attachmentOptions['destination'] . '_thumb', getAttachmentFilename($thumb_filename, $attachmentOptions['thumb'], $id_folder, false, $thumb_file_hash));
			}
		}
	}

	return true;
} 

// Recursive function to retrieve avatar files
// Lame copy of Sources/Profile-Modify.php with small modifications
function getAvatars($directory, $level)
{
	global $xf_dir, $smf_dir;
	$result = array();

	// Open the directory..
	$avatar_path = $xf_dir . '/data/avatars';
	//echo $avatar_path . '<br>';
	$dirs = array();
	$files = array();
	$mem_rename = false;
	//dir --> read fails to read the folder if a folder named "0" is there. So, if it exists, we will rename it
	$full_path = $avatar_path . (!empty($directory) ? '/' . $directory : ''); 
	$temp_path = $full_path . '/0';
	//echo $full_path . '<br>';
	//echo $temp_path . '<br>';
	$dir = @dir($temp_path);
	if ($dir) //If the directory exists, we rename it!!!
	{
		$dir->close();
		rename($full_path . '/0', $full_path . '/zzz');
		$mem_rename = true;
	}
	unset($temp_path);
	$dir = dir($full_path);
	
	while ($line = $dir->read())
	{
		//echo $line . '<br>';
		if (in_array($line, array('.', '..', 'blank.gif', 'index')))
			continue;

		if (is_dir($avatar_path . '/' . $directory . (!empty($directory) ? '/' : '') . $line)) 
			$dirs[] = $line;
		else
			$files[] = $line;
	}
	$dir->close();
	// echo '<pre>';
	// print_r($dirs);
	// print_r($files);
	// echo '</pre>';


	// Sort the results...
	natcasesort($dirs);
	natcasesort($files);

	//We don't need this
	// if ($level == 0)
	// {
		// $result[] = array(
			// 'filename' => 'blank.gif',
			// 'checked' => false,
			// 'name' => '(no pic)',
			// 'is_dir' => false
		// );
	// }

	foreach ($dirs as $line)
	{
		$tmp = getAvatars($directory . (!empty($directory) ? '/' : '') . $line, $level + 1); 
	
		if (!empty($tmp))
			$result[] = array(
				'filename' => htmlspecialchars($line),
				'checked' => true,
				'name' => '[' . htmlspecialchars(str_replace('_', ' ', $line)) . ']',
				'is_dir' => true,
				'files' => $tmp
		);
		unset($tmp);
	}

	foreach ($files as $line)
	{

		$filename = substr($line, 0, (strlen($line) - strlen(strrchr($line, '.'))));
		$extension = substr(strrchr($line, '.'), 1);
		//echo $filename . '.' . $extension . '<br>';


		// Make sure it is an image.
		if (strcasecmp($extension, 'gif') != 0 && strcasecmp($extension, 'jpg') != 0 && strcasecmp($extension, 'jpeg') != 0 && strcasecmp($extension, 'png') != 0 && strcasecmp($extension, 'bmp') != 0)
			continue;

		$result[] = array(
			'filename' => htmlspecialchars($line),
			'checked' => true,
			'name' => htmlspecialchars(str_replace('_', ' ', $filename)),
			'is_dir' => false,
		);
		//if ($level == 1)
		//	$context['avatar_list'][] = $directory . '/' . $line;
	}
	
	//if we renamed the "0" folder, then set it back to normal
	if ($mem_rename)
		rename($full_path . '/zzz', $full_path . '/0');
	return $result;
} 


function generateRandomString($length = 60)
{
    return substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, $length);
}

?>