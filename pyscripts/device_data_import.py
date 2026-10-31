import pandas as pd

def generate_sqlite_inserts(csv_file, table_name, batch_size=500):
    # Read CSV into a DataFrame
    df = pd.read_csv(csv_file)

    # Prepare insert query prefix with table and columns
    columns = ', '.join(df.columns)
    query_prefix = f'INSERT INTO {table_name} ({columns}) VALUES '

    # Collect all values formatted for SQL insert statements
    insert_queries = []
    for i in range(0, len(df), batch_size):
        # Fetch a batch of rows
        batch_df = df[i:i + batch_size]

        # Format each row as a SQL-compatible string
        values = [
            f"({', '.join([f'\"{row[col]}\"' if col == 'date' else repr(row[col]) for col in df.columns])})"
            for _, row in batch_df.iterrows()
        ]

        # Combine the prefix and values
        insert_query = query_prefix + ', '.join(values) + ';'
        insert_queries.append(insert_query)

    return insert_queries

# Usage example
csv_file = 'missing-data.csv'
table_name = 'pulse_device_data'
queries = generate_sqlite_inserts(csv_file, table_name)

# Optionally, write queries to a file or execute them in SQLite
with open('insert_queries.sql', 'w') as file:
    for query in queries:
        file.write(query + '\n')
