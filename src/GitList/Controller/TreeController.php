<?php

namespace GitList\Controller;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Request;

class TreeController implements ControllerProviderInterface
{
    public function connect(Application $app)
    {
        $route = $app['controllers_factory'];

        $route->get('{repo}/tree/{branch}/{tree}/', $treeController = function($repo, $branch = '', $tree = '') use ($app) {

            $repository = $app['git']->getRepositoryCached($app['git.repos'], $repo);

            if (!$branch) {
                $branch = $repository->getHead();
            }

            list($branch, $tree) = $app['util.repository']->extractRef($repository, $branch, $tree);
            $files = $repository->getTree($tree ? "$branch:\"$tree\"/" : $branch);
            $breadcrumbs = $app['util.view']->getBreadcrumbs($tree);

            $parent = null;
            if (($slash = strrpos($tree, '/')) !== false) {
                $parent = substr($tree, 0, $slash);
            } elseif (!empty($tree)) {
                $parent = '';
            }

            return $app['twig']->render('tree.twig', array(
                'files'          => $files->output(),
                'repo'           => $repo,
                'branch'         => $branch,
                'path'           => $tree ? $tree . '/' : $tree,
                'parent'         => $parent,
                'breadcrumbs'    => $breadcrumbs,
                'branches'       => $repository->getBranches(),
                'tags'           => $repository->getTags(),
                'readme'         => $app['util.repository']->getReadme($repository, $branch),
            ));
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
          ->assert('branch', '[\w-._\/]+')
          ->assert('tree', '.+')
          ->bind('tree');

        $route->post('{repo}/tree/{branch}/search', function(Request $request, $repo, $branch = '', $tree = '') use ($app) {

            $repository = $app['git']->getRepositoryCached($app['git.repos'], $repo);
            $path = $repository->getPath();

            $repository = $app['git']->getRepository($path );
            if (!$branch) {
                $branch = $repository->getHead();
            }

            $breadcrumbs = $app['util.view']->getBreadcrumbs($tree);
            $results = $repository->searchTree($request->get('query'), $branch);

            return $app['twig']->render('search.twig', array(
                'results'        => $results,
                'repo'           => $repo,
                'branch'         => $branch,
                'path'           => $tree,
                'breadcrumbs'    => $breadcrumbs,
                'branches'       => $repository->getBranches(),
                'tags'           => $repository->getTags(),
            ));
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
          ->assert('branch', '[\w-._\/]+')
          ->bind('search');


        # WRI: Changed order of this and next statement, because order
        # appears to be important, and the other statement got precedence
        $route->get('{repo}/{format}ball/{branch}', function($repo, $format, $branch) use ($app) {
            $repo_item = $app['git']->getRepositoryCached($app['git.repos'], $repo);
            $path = $repo_item->getPath();

            $repository = $app['git']->getRepository($path );

            $tree = $repository->getBranchTree($branch);

            if (false === $tree) {
                return $app->abort(404, 'Invalid commit or tree reference: ' . $branch);
            }

            $file = $app['cache.archives'] . DIRECTORY_SEPARATOR
                    . $repo . DIRECTORY_SEPARATOR
                    . substr($tree, 0, 2) . DIRECTORY_SEPARATOR
                    . substr($tree, 2)
                    . '.'
                    . $format;

            if (!file_exists($file)) {
                $repository->createArchive($tree, $file, $format);
            }

            return new StreamedResponse(function () use ($file) {
                readfile($file);
            }, 200, array(
                'Content-type' => ('zip' === $format) ? 'application/zip' : 'application/x-tar',
                'Content-Description' => 'File Transfer',
                'Content-Disposition' => 'attachment; filename="'.$repo.'-'.substr($tree, 0, 6).'.'.$format.'"',
                'Content-Transfer-Encoding' => 'binary',
            ));
        })->assert('format', '(zip|tar)')
          ->assert('repo', $app['util.routing']->getRepositoryRegex())
          ->assert('branch', '[\w-._\/]+')
          ->bind('archive');


        $route->get('{repo}/{branch}/', function($repo, $branch) use ($app, $treeController) {
            return $treeController($repo, $branch);
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
          ->assert('branch', '[\w-._\/]+')
          ->bind('branch');

        $route->get('{repo}/', function($repo) use ($app, $treeController) {
            return $treeController($repo);
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
          ->bind('repository');


        return $route;
    }
}
