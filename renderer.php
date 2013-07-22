<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Videofile module renderering methods are defined here.
 *
 * @package    mod_videofile
 * @copyright  2013 Jonas Nockert <jonasnockert@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/videofile/locallib.php');

/**
 * Videofile module renderer class
 */
class mod_videofile_renderer extends plugin_renderer_base {

    /**
     * Renders the videofile page header.
     *
     * @param videofile videofile
     * @return string
     */
    public function video_header($videofile) {
        global $CFG;

        $output = '';

        $name = format_string($videofile->get_instance()->name,
                              true,
                              $videofile->get_course());
        $title = $this->page->course->shortname . ": " . $name;

        $coursemoduleid = $videofile->get_course_module()->id;
        $context = context_module::instance($coursemoduleid);

        // Add video.js css and js files.
        /* FIXME Needs dev-version of video.js for now, otherwise videojs doesn't
            get defined and the below inline redefinition of videojs.options.flash.swf
            fails. */
        $this->page->requires->css('/mod/videofile/video-js/video-js.min.css');
        $this->page->requires->js('/mod/videofile/video-js/video.dev.js', true);
        $this->page->requires->js_init_code(
            'videojs.options.flash.swf = "' .
            new moodle_url($CFG->wwwroot . '/mod/videofile/video-js/video-js.swf') .
            '";');

        // Header setup.
        $this->page->set_title($title);
        $this->page->set_heading($this->page->course->fullname);

        $output .= $this->output->header();
        $output .= $this->output->heading($name, 3);

        if (!empty($videofile->get_instance()->intro)) {
            $output .= $this->output->box_start('generalbox boxaligncenter', 'intro');
            $output .= format_module_intro('videofile',
                                           $videofile->get_instance(),
                                           $coursemoduleid);
            $output .= $this->output->box_end();
        }

        return $output;
    }

    /**
     * Render the footer
     *
     * @return string
     */
    public function video_footer() {
        return $this->output->footer();
    }

    /**
     * Render the videofile page
     *
     * @param videofile videofile
     * @return string The page output.
     */
    public function video_page($videofile) {
        $output = '';
        $output .= $this->video_header($videofile);
        $output .= $this->video($videofile);
        $output .= $this->video_footer();

        return $output;
    }


    /**
     * Utility function for getting a file URL
     *
     * @param stored_file $file
     * @param string $areaname file area name (e.g. "videos")
     * @return string file url
     */
    private function util_get_file_url($file, $areaname) {
        global $CFG;

        $wwwroot = $CFG->wwwroot;

        $contextid = $file->get_contextid();
        $filename = $file->get_filename();
        $filepath = $file->get_filepath();
        $itemid = $file->get_itemid();

        return $wwwroot . '/pluginfile.php/' .  $contextid . '/mod_videofile/' .
            $areaname . $filepath . $itemid . '/' . $filename;
    }

    /**
     * Utility function for getting area files
     *
     * @param int $contextid
     * @param string $areaname file area name (e.g. "videos")
     * @return array of stored_file objects
     */
    private function util_get_area_files($contextid, $areaname) {
        $fs = get_file_storage();
        return $fs->get_area_files($contextid,
                                   'mod_videofile',
                                   $areaname,
                                   false,
                                   'itemid, filepath, filename',
                                   false);
    }

    /**
     * Renders videofile video
     *
     * @param videofile $videofile
     * @return string HTML
     */
    public function video(videofile $videofile) {
        $output  = '';
        $output .= $this->output->container_start('videofile');

        $contextid = $videofile->get_context()->id;

        // Get poster image.
        $posterurl = null;
        $posters = $this->util_get_area_files($contextid, 'posters');
        foreach ($posters as $file) {
            $posterurl = $this->util_get_file_url($file, 'posters');
            break; // Only one poster allowed.
        }
        if (!$posterurl) {
            $posterurl = $this->pix_url('moodle-logo', 'videofile');
        }

        // Render video element.
        $output .= html_writer::start_tag(
            'video',
            array('id' => 'videofile-' . $videofile->get_instance()->id,
                  'class' => 'video-js vjs-default-skin',
                  'controls' => 'controls',
                  'preload' => 'auto',
                  'width' => $videofile->get_instance()->width,
                  'height' => $videofile->get_instance()->height,
                  'poster' => $posterurl,
                  'data-setup' => '{}')
        );

        // Render video source elements.
        $videos = $this->util_get_area_files($contextid, 'videos');
        foreach ($videos as $file) {
            if ($mimetype = $file->get_mimetype()) {
                $videourl = $this->util_get_file_url($file, 'videos');

                $output .= html_writer::empty_tag(
                    'source',
                    array('src' => $videourl,
                          'type' => $mimetype)
                );
            }
        }

        // Render caption tracks.
        $first = true;
        $captions = $this->util_get_area_files($contextid, 'captions');
        foreach ($captions as $file) {
            if ($mimetype = $file->get_mimetype()) {
                $captionurl = $this->util_get_file_url($file, 'captions');

                // Get or construct caption label for video.js player.
                $filename = $file->get_filename();
                $dot = strrpos($filename, ".");
                if ($dot) {
                    $label = substr($filename, 0, $dot);
                } else {
                    $label = $filename;
                }

                // Perhaps filename is a three letter ISO 6392 language code (e.g. eng, swe)?
                if (preg_match('/^[a-z]{3}$/', $label)) {
                    $maybelabel = get_string($label, 'core_iso6392');

                    /* Strings not in language files come back as [[string]], don't
                       use those for labels. */
                    if (substr($maybelabel, 0, 2) !== '[[' ||
                            substr($maybelabel, -2, 2) === ']]') {
                        $label = $maybelabel;
                    }
                }

                $options = array('kind' => 'captions',
                                 'src' => $captionurl,
                                 'label' => $label);
                if ($first) {
                    $options['default'] = 'default';
                    $first = false;
                }

                // Track seems to need closing tag in IE9 (!).
                $output .= html_writer::tag('track', '', $options);
            }
        }

        $output .= html_writer::end_tag('video');
        $output .= $this->output->container_end(); // End of videofile.

        return $output;
    }
}