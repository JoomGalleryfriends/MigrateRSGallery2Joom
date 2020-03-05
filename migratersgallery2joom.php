<?php
/******************************************************************************\
**   JoomGallery RSGallery2 migration script 3.0.0                            **
**   By: JoomGallery::ProjectTeam                                             **
**   Copyright (C) 2020  JoomGallery::ProjectTeam                             **
**   Released under GNU GPL Public License                                    **
**   License: http://www.gnu.org/copyleft/gpl.html or have a look             **
**   at administrator/components/com_joomgallery/LICENSE.TXT                  **
\******************************************************************************/

/*******************************************************************************
**   Migration of DB and Files from RSGallery2 4.x to JoomGallery 3.x JUX     **
**   On the fly generating of categories in db and file system                **
**   copy or moving the images in the new categories                          **
**   not migrated are user rights from RSGallery2                             **
*******************************************************************************/

defined('_JEXEC') or die('Direct Access to this location is not allowed.');

class JoomMigrateRSGallery2Joom extends JoomMigration
{
  /**
   * The name of the migration
   * (should be unique)
   *
   * @var   string
   * @since 3.0
   */
  protected $migration = 'rsgallery2joom';

  /**
   * Properties for paths and database table names of old RSGallery to migrate from
   *
   * @var   string
   * @since 1.6
   */
  protected $path_originals;
  protected $path_details;
  protected $path_thumbnails;
  protected $table_images;
  protected $table_categories;
  protected $table_comments;
  protected $table_users;

  /**
   * Constructor
   *
   * @return  void
   * @since   1.6
   */
  public function __construct()
  {
    parent::__construct();

    // Create the image paths and table names, a '/' at the end of the path is not allowed!
    $prefix = $this->getStateFromRequest('prefix', 'prefix', '', 'cmd');
    $path   = $this->getStateFromRequest('path', 'path', '', 'string');
    $this->path_originals     = JPath::clean($path.'/original');
    $this->path_details       = JPath::clean($path.'/display');
    $this->path_thumbnails    = JPath::clean($path.'/thumb');
    $this->table_images       = $prefix.'rsgallery2_files';
    $this->table_categories   = $prefix.'rsgallery2_galleries';
    $this->table_comments     = $prefix.'rsgallery2_comments';
    $this->table_users        = $prefix.'users';
    // Categories and images has no owner
    $this->checkOwner         = true;
  }

  /**
   * Checks requirements for migration
   *
   * @return  void
   * @since   1.6
   */
  public function check($dirs = array(), $tables = array(), $xml = false, $min_version = false, $max_version = false)
  {
    $tables = array($this->table_images,
                    $this->table_categories,
                    $this->table_comments,
                    $this->table_users);

    $dirs = array($this->path_originals,
                  $this->path_thumbnails);

    parent::check($dirs, $tables, $xml, $min_version, $max_version);
  }

  /**
   * Main migration function
   *
   * @return  void
   * @since   1.6
   */
  protected function doMigration()
  {
    $task = $this->getTask('categories');

    switch($task)
    {
      case 'categories':
        $this->migrateCategories();
        // Break intentionally omited
      case 'rebuild':
        $this->rebuild();
        // Break intentionally omited
      case 'images':
        $this->migrateImages();
        // Break intentionally omited
      case 'comments':
        $this->migrateComments();
        // Break intentionally omited
      default:
        break;
    }
  }

  /**
   * Returns the maximum category ID of RSGallery.
   *
   * @return  int The maximum category ID of RSGallery
   * @since   1.6
   */
  protected function getMaxCategoryId()
  {
    $query = $this->_db2->getQuery(true)
          ->select('MAX(id)')
          ->from($this->table_categories);
    $this->_db2->setQuery($query);

    return $this->runQuery('loadResult', $this->_db2);
  }

  /**
   * Migrates all categories
   *
   * @return  void
   * @since   1.6
   */
  protected function migrateCategories()
  {
    $this->writeLogfile('Start migrating categories');

    $query = $this->_db2->getQuery(true)
          ->select('*')
          ->from($this->table_categories);
    $this->prepareTable($query, $this->table_categories, 'parent', array(0));

    while($cat = $this->getNextObject())
    {
      // Make information accessible for JoomGallery
      $cat->cid         = $cat->id;
      $cat->name        = $cat->name;
      $cat->description = $cat->description;
      $cat->parent_id   = $cat->parent;
      $cat->ordering    = $cat->ordering;
      $cat->published   = $cat->published;

      if(!$this->checkOwner)
      {
        $cat->owner = $cat->uid;
      }

      $this->createCategory($cat);

      $this->markAsMigrated($cat->id, 'id', $this->table_categories);

      if(!$this->checkTime())
      {
        $this->refresh();
      }
    }

    $this->resetTable($this->table_categories);

    $this->writeLogfile('Categories successfully migrated');
  }

  /**
   * Migrates all images
   *
   * @return  void
   * @since   1.6
   */
  protected function migrateImages()
  {
    $this->writeLogfile('Start migrating images');

    $query = $this->_db2->getQuery(true)
          ->select('i.*, u.username')
          ->from($this->table_images.' AS i')
          ->leftJoin($this->table_users.' AS u ON i.userid = u.id');
    $this->prepareTable($query);

    while($row = $this->getNextObject())
    {
      $original = $this->path_originals.'/'.$row->name;

      if(!JFile::exists($original))
      {
        if(!JFile::exists($original))
        {
          $this->setError('Original-Image not found: '.$original);

          continue;
        }
      }

      $row->id          = $row->id;
      $row->catid       = $row->gallery_id;
      $row->imgtitle    = $row->title;
      $row->imgtext     = $row->descr;
      $row->imgdate     = $row->date;
      $row->published   = $row->published;
      $row->imgfilename = $row->name;
      $row->imgvotes    = $row->votes;
      $row->imgvotesum  = $row->rating;                                                    // Table #_joomgallery_votes are not filled !
      $row->hits        = $row->hits;
      $row->approved    = '1';
      $row->ordering    = $row->ordering;

      if(!$this->checkOwner)
      {
        $row->owner     = $row->userid;
      }

      // Thumbs and detais are new created according to the current settings in configuration manager
      $this->moveAndResizeImage($row, $original, null, null, true);

      if(!$this->checkTime())
      {
        $this->refresh('images');
      }
    }

    $this->resetTable();

    $this->writeLogfile('Images successfully migrated');
  }

  /**
   * Migrate all comments
   *
   * @return  void
   * @since   1.6
   */
  protected function migrateComments()
  {
    $this->writeLogfile('Start migrating comments');

    $query = $this->_db2->getQuery(true)
          ->select('*')
          ->from($this->table_comments);
    $this->prepareTable($query);

    while($row = $this->getNextObject())
    {
      $row->cmtid       = $row->id;
      $row->cmtpic      = $row->item_id;
      $row->cmtname     = $row->user_name;
      $row->cmttext     = $row->comment;
      $row->cmtip       = $row->user_ip;
      $row->cmtdate     = $row->datetime;
      $row->published   = $row->published;
      $row->userid      = $row->userid;

      $this->createComment($row);

      if(!$this->checkTime())
      {
        $this->refresh('comments');
      }
    }

    $this->resetTable();

    $this->writeLogfile('Comments successfully migrated');
  }
}