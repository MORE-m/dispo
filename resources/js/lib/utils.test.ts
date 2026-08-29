import { expect, test } from 'vitest';
import { cn } from './utils';

test('cn merges class names', () => {
    expect(cn('px-2', 'px-4')).toContain('px-4');
});
