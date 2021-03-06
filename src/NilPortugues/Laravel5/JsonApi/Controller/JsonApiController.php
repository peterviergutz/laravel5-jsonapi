<?php
/**
 * Author: Nil Portugués Calderó <contact@nilportugues.com>
 * Date: 12/7/15
 * Time: 12:17 AM.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NilPortugues\Laravel5\JsonApi\Controller;

use Illuminate\Routing\Controller;

/**
 * Class JsonApiController.
 */
abstract class JsonApiController extends Controller
{
    use JsonApiTrait;
}
