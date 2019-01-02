import React from 'react';
import Grid from "@material-ui/core/Grid/Grid";
import TextField from "@material-ui/core/TextField/TextField";
import Paper from "@material-ui/core/Paper/Paper";
import moment from 'moment';

export default function APILogsParameters(props) {
  const { textFilter, session, server, fromTime, toTime, onChange, className: classNameProp } = props;

  return (
    <Paper elevation={1} className={classNameProp}>
      <Grid container spacing={16} >
        <Grid item xs={4}>
          <TextField fullWidth
                     name="fromTime"
                     label="From Time"
                     value={fromTime}
                     onChange={onChange}
                     InputLabelProps={{
                       shrink: true,
                     }}
          />
        </Grid>
        <Grid item xs={4}>
          <TextField
            fullWidth
            name="toTime"
            label="To Time"
            value={toTime}
            onChange={onChange}
            InputLabelProps={{
              shrink: true,
            }}
          />
        </Grid>
        <Grid item xs={4}>
          <TextField
            fullWidth
            label="Serach Criteria"
            name={'textFilter'}
            value={textFilter}
            onChange={onChange}
          />
        </Grid>
        <Grid item xs={4}>
          <TextField fullWidth
                     name="server"
                     label="Server"
                     value={server}
                     onChange={onChange}
                     InputLabelProps={{
                       shrink: true,
                     }}
          />
        </Grid>
        <Grid item xs={4}>
          <TextField
            fullWidth
            name="session"
            label="Session"
            value={session}
            onChange={onChange}
            InputLabelProps={{
              shrink: true,
            }}
          />
        </Grid>
      </Grid>
    </Paper>
  )
}
