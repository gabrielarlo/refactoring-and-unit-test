# DigitalTolk Assessment

## Refactor
- I put comment on every line that I refactored.
- Somewhat if the developer is using 8.0 and above. The code in __construct is very possible.
- Using guard clause or early return will clean the code to make it more readable.
- Using table base or Enum for variables is a good idea for security and consistency.
- It's a best practice to have return type for every function.
- if it is in laravel best to use the Facade or helper.
- Also the Job Model as model in Booking is unrelated and messy usage.
- Avoid using type values, instead using enumerations.
- When naming a variable, it should be related to its usage and be descriptive.
- If it is for API, then it should return JSON, except for some cases it in needs to.
- Before the line for return, there should be a line for adding one line before return.
- I suggest to use single quote instead o double quote.
- I suggest to use camelCase for methods and snake_case for variables and keys.
- Optional, Sort imports alphabetically, and remove unused imports.
- Best to use the custom attribute from models.
- For using chained methods and gets long, I suggest to wrap it and make it per line.
- use curly brackets for conditions (not for 1 liner conditions).

NOTE: In general the code is good but bit messy.

## Unit Test
- I created 2 files for unit test under `tests/Unit/` directory.
