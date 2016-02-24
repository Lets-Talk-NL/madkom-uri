<?php

namespace spec\Madkom\Uri;

use InvalidArgumentException;
use Madkom\Uri\Path;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

/**
 * Class PathSpec
 * @package spec\Madkom\Uri
 * @author Michał Brzuchalski <m.brzuchalski@madkom.pl>
 * @mixin Path
 */
class PathSpec extends ObjectBehavior
{
    function it_is_initializable()
    {
        $this->shouldHaveType(Path::class);
    }

    function it_can_add_and_remove_segments()
    {
        $paths = ['a', 'b', 'b', ''];
        $this->beConstructedWith($paths);
        $this->getSegments()->shouldReturn($paths);
        $this->toString()->shouldReturn('/a/b/b/');
        $this->__toString()->shouldReturn('/a/b/b/');
    }

    function it_can_return_empty_string_path()
    {
        $this->toString()->shouldReturn('/');
    }

    function it_can_return_string_representation_with_urlencoded_segments()
    {
        $segment = 'ążśźęćłóń';
        $this->beConstructedWith([$segment]);
        $this->toString()->shouldReturn(sprintf("/%s", urlencode($segment)));
        $this->__toString()->shouldReturn(sprintf("/%s", urlencode($segment)));
    }

    function it_fails_on_malformed_segments_initialization()
    {
        $this->shouldThrow(InvalidArgumentException::class)->during('__construct', [['a#2']]);
        $this->shouldThrow(InvalidArgumentException::class)->during('__construct', [['a?2']]);
        $this->shouldThrow(InvalidArgumentException::class)->during('__construct', [['a/2']]);
    }

    function it_can_construct_from_string()
    {
        $self = self::createFromString('/a/b/c');
        $self->shouldReturnAnInstanceOf(Path::class);
        $self->getSegments()->shouldReturn(['a', 'b', 'c']);
    }
}
